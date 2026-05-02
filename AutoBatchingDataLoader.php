<?php

class AutoBatchingMySqlConnection extends \Illuminate\Database\MySqlConnection
{
    public function __construct($pdo, $database = '', $tablePrefix = '', array $config = [])
    {
        parent::__construct($pdo, $database, $tablePrefix, $config);
    }

    public function nativeSelect($query, $bindings = [], $useReadPdo = true)
    {
        // flush() 调用此方法执行合并后的 SQL。
        // 必须拿到池中当前协程持有的真实 Connection（含真实 PDO），
        // 然后以它为 $this 调用 Illuminate\Database\Connection::select()，
        // 从而完全绕过本类的 select() 重写（AutoBatching）和 proxyPdo 匿名类。
        $cid = \Swoole\Coroutine::getCid();
        if ($cid > 0 && isset($GLOBALS['coroutine_db_proxy'])) {
            $realConn = $GLOBALS['coroutine_db_proxy']->getRealConnection();
            if ($realConn instanceof \Illuminate\Database\Connection) {
                // 以 $realConn 为 $this，调用祖先类方法，跳过 AutoBatching 重写
                $fn = \Closure::bind(
                    function($q, $b, $r) { return parent::select($q, $b, $r); },
                    $realConn,
                    \Illuminate\Database\MySqlConnection::class
                );
                return $fn($query, $bindings, $useReadPdo);
            }
        }
        // 降级：非协程环境（proxyPdo 不存在），直接走父类
        return parent::select($query, $bindings, $useReadPdo);
    }

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        $cid = \Swoole\Coroutine::getCid();
        
        // 极速拦截：如果此协程在前置 LSCache 判断时已经查过了当前用户的 Token，直接缓存命中返回！
        if ($cid > 0 && !empty($bindings)) {
            $ctx = \Swoole\Coroutine::getContext();
            if ($ctx && isset($ctx['prefetched_access_token'])) {
                if (preg_match('/^\s*select\s+\*\s+from\s+(`?[a-zA-Z0-9_]+`?)\s+where\s+(`?[a-zA-Z0-9_]+`?\.)?`?token`?\s*=\s*\?\s+limit\s+1\s*$/i', $query)) {
                    $tokenObj = $ctx['prefetched_access_token'];
                    if ($bindings[0] === $tokenObj->token) {
                        return [ $tokenObj ];
                    }
                }
            }
        }

        $isMatch = false;
        $reason = "No Regex Match";
        
        $isExists = false;
        $existsTable = '';
        $existsColumn = '';
        $existsAlias = '';

        // 特殊指纹识别：拦截 EXISTS 子查询并转换为 IN 查询
        if (preg_match('/^\s*select\s+exists\s*\(\s*select\s+\*\s+from\s+(`?[a-zA-Z0-9_]+`?)\s+where\s+.*?(?:`?[a-zA-Z0-9_]+`?\.)?(`?[a-zA-Z0-9_]+`?)\s*=\s*\?.*?\)\s+as\s+(`?[a-zA-Z0-9_]+`?)$/i', $query, $matches)) {
            $isExists = true;
            $existsTable = $matches[1];
            $existsColumn = $matches[2];
            $existsAlias = $matches[3];
            $isMatch = true;
            if ($cid <= 0) {
                $reason = "Not in Coroutine (CID <= 0)";
                $isMatch = false;
            }
        } 
        // 核心指纹识别：放开单列条件限制，支持多条件和更复杂的 WHERE 结构
        // 必须避开 COUNT 等聚合查询，因为它们不返回对应的原始列
        else if (preg_match('/^\s*select\s+(.+?)\s+from\s+(`?[a-zA-Z0-9_]+`?)\s+where\s+(.+?\?.*)$/i', $query, $matches)) {
            $selectClause = $matches[1];
            
            if (preg_match('/(exists|count|sum|avg|max|min)\s*\(/i', $selectClause)) {
                $reason = "Aggregation query ($selectClause) cannot be mapped safely";
            } else {
                $isMatch = true;
                if ($cid <= 0) {
                    $reason = "Not in Coroutine (CID <= 0)";
                    $isMatch = false;
                }
            }
        }

        if ($isMatch) {
            return AutoBatchingDataLoader::load($this, $query, $bindings, $useReadPdo, $isExists, $existsTable, $existsColumn, $existsAlias);
        }

        if (!$isMatch && defined('LOG_LEVEL') && LOG_LEVEL === 'debug') {
            $shortQuery = substr($query, 0, 150) . (strlen($query) > 150 ? '...' : '');
            
            // 获取调用栈以查明幕后真凶（追踪两层，避免只显示底层封装类）
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);
            $callers = [];
            foreach ($trace as $t) {
                // 跳过 Laravel 和 Swoole 代理的调用
                if (isset($t['class']) && (
                    strpos($t['class'], 'Illuminate\\Database') !== false ||
                    strpos($t['class'], 'AutoBatching') !== false ||
                    strpos($t['class'], 'CoroutineDbProxy') !== false ||
                    strpos($t['class'], 'Flarum\\Database\\AbstractModel') !== false
                )) {
                    continue;
                }
                $class = $t['class'] ?? '';
                $type = $t['type'] ?? '';
                $func = $t['function'] ?? '';
                if ($class) {
                    $callers[] = $class . $type . $func;
                    if (count($callers) >= 2) break;
                }
            }
            $callerInfo = implode(' <- ', $callers);
            if (empty($callerInfo)) $callerInfo = 'Unknown caller';
            
            echo "[AutoBatching] ❌ BYPASSED (CID:{$cid}) [$reason] by $callerInfo: $shortQuery\n";
        }

        return parent::select($query, $bindings, $useReadPdo);
    }
    
    public function selectOne($query, $bindings = [], $useReadPdo = true)
    {
        $records = $this->select($query, $bindings, $useReadPdo);
        return count($records) > 0 ? reset($records) : null;
    }
}

class AutoBatchingDataLoader
{
    private static array $batches = [];

    public static function load($conn, string $query, array $bindings, bool $useReadPdo, bool $isExists = false, string $existsTable = '', string $existsColumn = '', string $existsAlias = '')
    {
        $cid = \Swoole\Coroutine::getCid();
        $batchKey = md5($query);

        // 如果该批次还不存在，当前协程作为 Owner 负责兜底执行
        if (!isset(self::$batches[$batchKey])) {
            self::$batches[$batchKey] = [
                'owner'       => $cid,
                'conn'        => $conn,
                'query'       => $query,
                'useReadPdo'  => $useReadPdo,
                'pending'     => [],
                'results'     => [],
                'flushing'    => false,   // 关键标志：flush() 执行期间设为 true，阻止迟到者注册
                'isExists'    => $isExists,
                'existsTable' => $existsTable,
                'existsColumn'=> $existsColumn,
                'existsAlias' => $existsAlias,
            ];

            self::$batches[$batchKey]['pending'][$cid] = $bindings;

            // Owner 协程休眠 1 毫秒（非阻塞），给其他并发序列化协程让出 CPU 来注册自己
            \Swoole\Coroutine\System::sleep(0.001);

            // 醒来后，标记为 flushing，然后执行合并！
            self::$batches[$batchKey]['flushing'] = true;
            try {
                self::flush($batchKey);
            } finally {
                // 异常安全网：确保所有兄弟协程都能被唤醒，防止永久死锁
                if (isset(self::$batches[$batchKey])) {
                    foreach (self::$batches[$batchKey]['pending'] as $waitCid => $b) {
                        if ($waitCid !== $cid && \Swoole\Coroutine::exists($waitCid)) {
                            \Swoole\Coroutine::resume($waitCid);
                        }
                    }
                }
            }

            $result = self::$batches[$batchKey]['results'][$cid] ?? [];
            unset(self::$batches[$batchKey]);

            if ($result instanceof \Throwable) {
                throw $result;
            }
            return $result;

        } else {
            // 如果该批次正在执行 flush()（I/O 等待期间），迟到者不能注册，
            // 否则会永久 yield（没人唤醒）并拿到空 results，导致 bindings 错误（HY093）。
            // 直接降级走原始 parent::select() 即可。
            if (!empty(self::$batches[$batchKey]['flushing'])) {
                return $conn->nativeSelect($query, $bindings, $useReadPdo);
            }

            // 其他跟进来的协程，将自己注册后直接挂起
            self::$batches[$batchKey]['pending'][$cid] = $bindings;
            \Swoole\Coroutine::yield();

            // 被 Owner 唤醒后取走属于自己的数据
            $result = self::$batches[$batchKey]['results'][$cid] ?? [];
            unset(self::$batches[$batchKey]['results'][$cid]);

            if ($result instanceof \Throwable) {
                throw $result;
            }
            return $result;
        }
    }

    private static function flush(string $batchKey)
    {
        if (empty(self::$batches[$batchKey]['pending'])) {
            return;
        }

        $batch = self::$batches[$batchKey];
        $pending = $batch['pending'];
        $conn = $batch['conn'];
        $query = $batch['query'];
        $useReadPdo = $batch['useReadPdo'];
        $ownerCid = $batch['owner'];
        
        $isExists = $batch['isExists'];
        $existsTable = $batch['existsTable'];
        $existsColumn = $batch['existsColumn'];
        $existsAlias = $batch['existsAlias'];
        $conn = $batch['conn'];
        $query = $batch['query'];
        $useReadPdo = $batch['useReadPdo'];
        $ownerCid = $batch['owner'];

        // 清理 pending 标记，防止 finally 重复唤醒
        self::$batches[$batchKey]['pending'] = [];

        // 如果这个微秒窗口里只有 1 个协程，直接原样查，不要浪费算力改写 SQL
        if (count($pending) === 1) {
            try {
                self::$batches[$batchKey]['results'][$ownerCid] = $conn->nativeSelect(
                    $query,
                    $pending[$ownerCid] ?? [],
                    $useReadPdo
                );
            } catch (\Throwable $e) {
                self::$batches[$batchKey]['results'][$ownerCid] = $e;
            }
            return;
        }

        // --- 发生并发 N+1！启动智能合并 ---
        
        $uniqueValues = [];
        $firstBindings = null;
        $diffIndex = -1;

        foreach ($pending as $cid => $b) {
            if ($firstBindings === null) {
                $firstBindings = $b;
                continue;
            }
            if ($diffIndex === -1) {
                foreach ($b as $i => $val) {
                    if ($val !== $firstBindings[$i]) {
                        $diffIndex = $i;
                        break;
                    }
                }
            }
        }

        if ($diffIndex === -1) {
            $diffIndex = 0;
        }

        foreach ($pending as $cid => $b) {
            if (isset($b[$diffIndex]) && !in_array($b[$diffIndex], $uniqueValues, true)) {
                $uniqueValues[] = $b[$diffIndex];
            }
        }

        if ($isExists) {
            // Rewrite EXISTS query
            // Original: select exists(select * from tpolls where tpolls.post_id = ?) as exists
            // Rewrite to: select post_id as __batch_key from tpolls where tpolls.post_id IN (?, ?, ?)
            $rewrittenQuery = preg_replace(
                '/^\s*select\s+exists\s*\(\s*select\s+\*\s+from\s+(`?[a-zA-Z0-9_]+`?)\s+where\s+(.+?)\)\s+as\s+(`?[a-zA-Z0-9_]+`?)$/i', 
                "select $existsColumn as __batch_key from $existsTable where $2", 
                $query
            );
        } else {
            $rewrittenQuery = preg_replace('/\s*limit\s+1\s*$/i', '', $query);
        }

        $parts = explode('?', $rewrittenQuery);
        $newQuery = "";
        $newBindings = [];
        $fallbackToSequential = false;

        for ($i = 0; $i < count($parts) - 1; $i++) {
            if ($i === $diffIndex) {
                $trimmedPart = rtrim($parts[$i]);
                if (!str_ends_with($trimmedPart, '=')) {
                    // 非等值条件（如 >、<、LIKE）不能转换成 IN (?,?)，必须降级单条执行
                    $fallbackToSequential = true;
                    break;
                }
                $placeholders = implode(',', array_fill(0, count($uniqueValues), '?'));
                $trimmedPart = rtrim($trimmedPart, '=');
                $newQuery .= $trimmedPart . " IN (" . $placeholders . ")";
                foreach ($uniqueValues as $val) {
                    $newBindings[] = $val;
                }
            } else {
                $newQuery .= $parts[$i] . "?";
                $newBindings[] = $firstBindings[$i] ?? null;
            }
        }
        
        if ($fallbackToSequential) {
            foreach ($pending as $cid => $bindings) {
                try {
                    self::$batches[$batchKey]['results'][$cid] = $conn->nativeSelect($query, $bindings, $useReadPdo);
                } catch (\Throwable $e) {
                    self::$batches[$batchKey]['results'][$cid] = $e;
                }
                if ($cid !== $ownerCid && \Swoole\Coroutine::exists($cid)) {
                    \Swoole\Coroutine::resume($cid);
                }
            }
            return;
        }

        $newQuery .= end($parts);

        try {
            $mergedResults = $conn->nativeSelect($newQuery, $newBindings, $useReadPdo);
        } catch (\Throwable $e) {
            foreach ($pending as $cid => $bindings) {
                self::$batches[$batchKey]['results'][$cid] = $e;
                if ($cid !== $ownerCid && \Swoole\Coroutine::exists($cid)) {
                    \Swoole\Coroutine::resume($cid);
                }
            }
            return;
        }

        foreach ($pending as $cid => $bindings) {
            $diffValue = $bindings[$diffIndex];
            
            if ($isExists) {
                $isFound = false;
                foreach ($mergedResults as $row) {
                    if (isset($row->__batch_key) && (string)$row->__batch_key === (string)$diffValue) {
                        $isFound = true;
                        break;
                    }
                }
                $aliasStr = str_replace('`', '', $existsAlias);
                self::$batches[$batchKey]['results'][$cid] = [ (object) [ $aliasStr => $isFound ? 1 : 0 ] ];
            } else {
                $matchedRows = [];
                foreach ($mergedResults as $row) {
                    $arrayRow = (array) $row;
                    if (in_array($diffValue, $arrayRow, false)) {
                        $matchedRows[] = $row;
                        break;
                    }
                }
                self::$batches[$batchKey]['results'][$cid] = $matchedRows;
            }

            if ($cid !== $ownerCid && \Swoole\Coroutine::exists($cid)) {
                \Swoole\Coroutine::resume($cid);
            }
        }
    }

    public static function cleanup(): void
    {
        foreach (self::$batches as $batchKey => &$batch) {
            foreach ($batch['pending'] ?? [] as $cid => $bindings) {
                if ($cid !== $batch['owner'] && \Swoole\Coroutine::exists($cid)) {
                    \Swoole\Coroutine::resume($cid);
                }
            }
        }
        self::$batches = [];
    }
}

