<?php
/**
 * Flarum Swoole Coroutine Worker
 *
 * 基于 Swoole 的 Flarum 常驻内存入口，启用了 SWOOLE_HOOK_ALL 使 PDO/MySQL
 * 查询自动协程化，从而真正发挥多核服务器的并发能力。
 *
 * 依赖安装:
 *   pecl install swoole
 *   composer require laminas/laminas-diactoros  (Flarum 自带，无需额外安装)
 *
 * 启动:
 *   php flarum-worker-swoole.php
 *
 * 停止:
 *   kill $(cat /tmp/flarum-swoole.pid)
 *
 * 将此文件放置于 Flarum 根目录（与 vendor/ 同级）
 */
declare(strict_types=1);
// ============================================================
// 启动检查
// ============================================================
if (!extension_loaded('swoole')) {
    fwrite(STDERR, "[ERROR] Swoole 扩展未安装。请执行: pecl install swoole\n");
    exit(1);
}
require __DIR__ . '/vendor/autoload.php';
use Swoole\Http\Server;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface as PsrResponse;
// ============================================================
// 服务器配置
// ============================================================
$host        = '0.0.0.0';
$port        = 2345;
// ---------------------------------------------------------------
// Worker 数量策略（针对弱单核 + 3~5个并发API请求/页面 的场景）
//
// 不应该等于 CPU 核心数！原因：
//   - 你的访问量少，同时在线用户极少
//   - 每个页面打开会同时发起 3~5 个 API 请求
//   - 这 3~5 个请求如果能分配到不同的 Worker，就能在多个核心上真正并行
//   - Worker 越多，每个 Worker 占的 L1/L2 Cache 越少（弱单核更在乎 Cache 命中率）
//
// 推荐值：页面并发API数量的 1.5~2 倍，即 6~8 个
// 太少（<4）：3~5个并发请求排队等待
// 太多（>16）：Cache 抖动，单核调度开销上升，反而更慢
// ---------------------------------------------------------------
$workerCount = 8;  // 建议值：6~10，根据实际延迟测试调整
// ============================================================
// CLI 参数处理（支持 start / stop / reload）
// ============================================================
$pidFile = '/tmp/flarum-swoole.pid';
$action  = $argv[1] ?? 'start';
if ($action === 'stop') {
    if (!file_exists($pidFile)) {
        echo "[ERROR] PID 文件不存在，进程可能未在运行。\n";
        exit(1);
    }
    $pid = (int) file_get_contents($pidFile);
    if (posix_kill($pid, SIGTERM)) {
        echo "[OK] 已发送 SIGTERM 给进程 #{$pid}，服务正在停止...\n";
        @unlink($pidFile);
    } else {
        echo "[ERROR] 无法向进程 #{$pid} 发送信号，请手动执行: kill {$pid}\n";
    }
    exit(0);
}
if ($action === 'reload') {
    if (!file_exists($pidFile)) {
        echo "[ERROR] PID 文件不存在，进程可能未在运行。\n";
        exit(1);
    }
    $pid = (int) file_get_contents($pidFile);
    if (posix_kill($pid, SIGUSR1)) {
        echo "[OK] 已发送 SIGUSR1 给进程 #{$pid}，Worker 正在平滑重载...\n";
    } else {
        echo "[ERROR] 无法向进程 #{$pid} 发送信号。\n";
    }
    exit(0);
}
// 默认 start，继续向下执行启动逻辑
// ---------------------------------------------------------------
// SWOOLE_BASE 模式：Worker 进程直接监听端口，无 Master→Worker IPC 开销
// 对比 SWOOLE_PROCESS：每个请求省去一次 Unix Socket 数据拷贝（约 0.5~2ms）
// 对低延迟场景（而非高吞吐场景）这是最重要的架构选择
// 限制：不支持 sendMessage/task 跨Worker通信，但 Flarum 不需要这些
// ---------------------------------------------------------------
$server = new Server($host, $port, SWOOLE_BASE, SWOOLE_SOCK_TCP);
$server->set([
    'worker_num'            => $workerCount,
    'task_worker_num'       => 0,
    'max_request'           => 0,
    'reload_async'          => true,
    'max_wait_time'         => 60,
    'enable_reuse_port'     => true,
    'http_compression'      => false,
    // ---------------------------------------------------------------
    // TCP 层延迟优化（对弱单核低延迟场景影响显著）
    // ---------------------------------------------------------------
    // 禁用 Nagle 算法：Nagle 会将小包合并后再发送，引入约 40ms 的人为延迟
    // Flarum 的 API 响应 JSON 都是小包，必须禁用 Nagle 才能做到真正低延迟
    'open_tcp_nodelay'      => true,
    // TCP KeepAlive：检测死连接，防止僵尸连接占用 Worker 资源
    'open_tcp_keepalive'    => true,
    'tcp_keepidle'          => 60,    // 60秒无数据后开始探测
    'tcp_keepinterval'      => 10,    // 探测间隔 10 秒
    'tcp_keepcount'         => 3,     // 探测 3 次无响应则断开
    // ---------------------------------------------------------------
    // 内存与缓冲区调参（针对弱单核，不要设置过大，避免 Cache 污染）
    // ---------------------------------------------------------------
    // 单个响应最大 16MB：LESS 编译后的 CSS 资产响应可能较大
    'buffer_output_size'    => 16 * 1024 * 1024,
    // Socket 接收/发送缓冲区：设为 512KB 即可，过大浪费 L2 Cache
    'socket_buffer_size'    => 512 * 1024,
    // ---------------------------------------------------------------
    // 协程调度参数
    // ---------------------------------------------------------------
    // 单 Worker 协程上限：访问少时 10000 足够，设太高只是浪费内存
    // 每个协程栈默认 128KB，10000 个协程 = 约 1.2GB 内存（极限情况）
    'max_coroutine'         => 10000,
    // 协程栈初始大小：设为 8MB，确保 LESS 编译等深调用栈操作不会爆栈
    // 正常 API 请求实际消耗约 200~400KB，8MB 有大量余量但不影响正常请求性能
    'stack_size'            => 8 * 1024 * 1024,
    // ---------------------------------------------------------------
    // 进程管理
    // ---------------------------------------------------------------
    'daemonize'             => false,
    'pid_file'              => '/tmp/flarum-swoole.pid',
    'log_file'              => __DIR__ . '/storage/logs/swoole.log',
    'log_level'             => SWOOLE_LOG_WARNING,
]);
// ============================================================
// Worker 进程启动 - 每个 Worker 独立执行一次，结果常驻内存
// ============================================================
$server->on('workerStart', function (Server $server, int $workerId) {
    // ----------------------------------------------------------
    // 绝对禁止开启 SWOOLE_HOOK_ALL！
    // Flarum(Laravel) 的 Redis 和 MySQL 连接在框架底层是“单例”设计。
    // 如果开启 Hook，Swoole 会把底层的 stream 替换成协程 socket。
    // 当多个并发请求（多个协程）同时尝试读写同一个 Redis 单例时，
    // Swoole 就会直接抛出 Fatal Error：Socket has already been bound to another coroutine
    // 这会导致整个 Worker 崩溃，连带你的 Flarum 也无法连接 Redis！
    // ----------------------------------------------------------
    // ----------------------------------------------------------
    // 内存配置：激进分配，应对偶发的大内存操作
    //
    // 背景：Flarum 在管理员清除缓存后的首次访问时，会触发 LESS 编译、
    // JS 资产打包等操作，峰值内存需求可高达 ~1GB。
    // 正常请求（ORM 水合 + JSON 序列化）仅需 20~60MB，这部分上限
    // 永远不会被跑满，因此激进的上限设置不会造成实际内存压力。
    // ----------------------------------------------------------
    ini_set('memory_limit', '1536M');  // 1.5GB：留出余量高于 1GB 峰值
    // ----------------------------------------------------------
    // LSCache 兼容层：环境伪装
    // 欺骗 Flarum LSCache 插件后台的服务器环境检测
    // ----------------------------------------------------------
    $_SERVER['X-LSCACHE'] = 'on';
    $_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed Web Server (Swoole Emulator)';
    echo "[Worker #{$workerId}] 启动中，正在加载 Flarum 应用 (memory_limit=1536M)...\n";
    try {
        $site = require __DIR__ . '/site.php';
        $app  = $site->bootApp();
    } catch (\Throwable $e) {
        fwrite(STDERR, "[Worker #{$workerId}] Flarum 启动失败: {$e->getMessage()}\n");
        fwrite(STDERR, $e->getTraceAsString() . "\n");
        $server->stop($workerId);
        return;
    }
    // 将 Flarum 请求处理器和容器挂在全局变量里，供每次 onRequest 使用
    $GLOBALS['flarum_handler']   = $app->getRequestHandler();
    $GLOBALS['flarum_container'] = $app->getContainer();
    $GLOBALS['flarum_worker_id'] = $workerId;
    // ----------------------------------------------------------
    // LSCache 兼容层：Redis 连接配置 (动态从 extend.php 读取)
    // ----------------------------------------------------------
    try {
        $redisSettings = [];
        $extendFilePath = __DIR__ . '/extend.php';
        
        if (file_exists($extendFilePath)) {
            $extenders = require $extendFilePath;
            if (is_array($extenders)) {
                foreach ($extenders as $extender) {
                    if (is_object($extender) && str_ends_with(get_class($extender), 'Redis\Extend\Redis')) {
                        $ref = new \ReflectionClass($extender);
                        $ref = new \ReflectionClass($extender);
                        
                        // FoF\Redis 将配置封存在了 protected $configuration 这个对象里
                        if ($ref->hasProperty('configuration')) {
                            $prop = $ref->getProperty('configuration');
                            $prop->setAccessible(true);
                            $configObj = $prop->getValue($extender);
                            
                            // 再针对 FoF\Redis\Configuration 这个对象进行第二次反射
                            if (is_object($configObj)) {
                                $configRef = new \ReflectionClass($configObj);
                                if ($configRef->hasProperty('config')) {
                                    $innerProp = $configRef->getProperty('config');
                                    $innerProp->setAccessible(true);
                                    $val = $innerProp->getValue($configObj);
                                    
                                    if (is_array($val) && (isset($val['path']) || isset($val['host']) || isset($val['database']))) {
                                        $redisSettings = $val;
                                        // 提取可能存在的多数据库配置 (useDatabaseWith)
                                        if ($configRef->hasProperty('databases')) {
                                            $dbProp = $configRef->getProperty('databases');
                                            $dbProp->setAccessible(true);
                                            $databases = $dbProp->getValue($configObj) ?: [];
                                            if (isset($databases['session'])) {
                                                $redisSettings['session_database'] = $databases['session'];
                                            }
                                        }
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        if (empty($redisSettings)) {
            echo "[Worker #{$workerId}] 致命错误：在 extend.php 中未提取到 FoF\Redis 配置！打印此时的所有 extenders：\n";
            if (isset($extenders) && is_array($extenders)) {
                var_dump($extenders);
            }
            // 如果连缓存配置都拿不到，那整个缓存加速引擎就等于废了，这里强行退出并抛错！
            throw new \Exception("提取 Redis 配置失败，服务启动终止。");
        }

        echo "[Worker #{$workerId}] LSCache 从 extend.php 成功读取 Redis 配置: " . json_encode($redisSettings) . "\n";
        
        // 建立主 Redis 连接 (用于缓存)
        $swooleRedis = new \Predis\Client($redisSettings);
        $swooleRedis->connect();
        $GLOBALS['swoole_redis'] = $swooleRedis;
        
        // 如果配置了独立的 Session 数据库，则建立专属连接
        if (isset($redisSettings['session_database'])) {
            $sessionSettings = $redisSettings;
            $sessionSettings['database'] = $redisSettings['session_database'];
            unset($sessionSettings['session_database']);
            $swooleSessionRedis = new \Predis\Client($sessionSettings);
            $swooleSessionRedis->connect();
            $GLOBALS['swoole_session_redis'] = $swooleSessionRedis;
            echo "[Worker #{$workerId}] 检测到独立 Session 数据库(DB:{$sessionSettings['database']})，已建立专属连接。\n";
        } else {
            $GLOBALS['swoole_session_redis'] = $swooleRedis;
        }

    } catch (\Throwable $e) {
        echo "[Worker #{$workerId}] LSCache: Redis 连接失败或读取配置失败，页面缓存拦截将禁用: {$e->getMessage()}\n";
    }
    // ----------------------------------------------------------
    // DB 保活定时器
    // 缩短到 1 小时：部分共享环境的 MySQL wait_timeout 低至 1~2 小时，
    // 4 小时心跳在这类环境下无效，会导致过夜后每次请求触发隐式重连。
    // ----------------------------------------------------------
    \Swoole\Timer::tick(3600 * 1000, function () use ($workerId) {
        try {
            $db = $GLOBALS['flarum_container']->make('db');
            $db->select('SELECT 1');
        } catch (\Throwable $e) {
            try {
                $GLOBALS['flarum_container']->make('db')->reconnect();
                echo "[Worker #{$workerId}] [" . date('H:i:s') . "] DB 连接已重建。\n";
            } catch (\Throwable $reconnectErr) {
                echo "[Worker #{$workerId}] [" . date('H:i:s') . "] DB 重连失败: {$reconnectErr->getMessage()}\n";
            }
        }
    });
    // ----------------------------------------------------------
    // 定时 Worker 轮换（解决堆碎片化慢性退化）
    //
    // 背景：PHP 的 Zend 内存分配器在 GC 回收对象后，被释放的内存只是
    // 原地留下"空洞"，无法被压缩整理。Worker 运行 6~8 小时后，堆里
    // 布满细碎空洞，CPU 寻址的 L1/L2 Cache 命中率持续下滑，
    // 表现为"睡一觉回来就慢了一倍"。
    //
    // 解决方案：每个 Worker 每 6 小时（各自错开启动时间）主动退出一次。
    // Swoole Manager 会在毫秒内拉起全新干净的替补 Worker，
    // 整个过程对用户完全透明（在线用户的请求会被其他 Worker 接管）。
    //
    // 各 Worker 错开时间：避免 8 个 Worker 同时重启导致的短暂容量下降。
    // ----------------------------------------------------------
    $recycleInterval = 6 * 3600 * 1000;  // 6 小时
    $recycleOffset   = $workerId * 45 * 1000;  // 每个 Worker 错开 45 秒
    \Swoole\Timer::after($recycleOffset, function () use ($server, $workerId, $recycleInterval) {
        \Swoole\Timer::tick($recycleInterval, function () use ($server, $workerId) {
            echo "[Worker #{$workerId}] [" . date('H:i:s') . "] 定时轮换：主动退出以清理堆碎片，Manager 将立即拉起新 Worker...\n";
            $server->stop($workerId);
        });
    });
    echo "[Worker #{$workerId}] Flarum 加载成功（DB心跳:1h, 定时轮换:6h）。\n";
});
// ============================================================
// 处理每个 HTTP 请求
// ============================================================
$server->on('request', function (SwooleRequest $swooleReq, SwooleResponse $swooleRes) {
    $startTime = microtime(true);
    // ----------------------------------------------------------
    // LSCache 兼容层：依据 Cache Key 直接命中缓存
    // ----------------------------------------------------------
    // 严格遵循 acpl-lscache 插件和 LiteSpeed .htaccess 的原始语义：
    // 不做任何多余的 cookie 状态猜测，完全依靠 Cache-Vary 生成唯一的 Cache Key。
    // 能否缓存、给谁缓存，100% 交由 Flarum 响应头 X-LiteSpeed-Cache-Control 决定。
    $method = strtoupper($swooleReq->server['request_method'] ?? 'GET');
    $uri = $swooleReq->server['request_uri'] ?? '/';
    $qs = $swooleReq->server['query_string'] ?? '';
    
    // ==========================================================
    // 终极缓存绕过验证：根据 Session 序列化特征精确识别游客
    // ==========================================================
    $isGuest = true;
    
    if (isset($swooleReq->header['authorization'])) {
        $isGuest = false;
    } elseif (!empty($swooleReq->cookie['flarum_remember'])) {
        $isGuest = false; // 携带 remember 令牌，直接交由 Flarum 辨别真伪
    } elseif (!empty($swooleReq->cookie['flarum_session']) && isset($GLOBALS['swoole_session_redis'])) {
        $sessionId = $swooleReq->cookie['flarum_session'];
        $redis = $GLOBALS['swoole_session_redis']; // 使用专门的 Session 库客户端
        
        // 尝试从 Redis 中获取 Session 数据（补充下划线前缀）
        $sessionData = $redis->get($sessionId) 
                    ?: $redis->get('flarum_cache:' . $sessionId) 
                    ?: $redis->get('flarum_session:' . $sessionId)
                    ?: $redis->get('flarum_cache_' . $sessionId)
                    ?: $redis->get('flarum_session_' . $sessionId);
        
        if ($sessionData) {
            // 根据你的发现：纯游客的 Laravel Session 序列化后长度固定为 118，且必定是 a:2
            // 只要不是以 s:118:"a:2:{s:6:"_token" 开头，说明它已经带有登录标识（如 access_token 等）或其它状态
            if (!str_starts_with($sessionData, 's:118:"a:2:{s:6:"_token"')) {
                $isGuest = false;
            }
        }
    }

    if ($isGuest && in_array($method, ['GET', 'HEAD']) && isset($GLOBALS['swoole_redis'])) {
        try {
            $redis = $GLOBALS['swoole_redis'];
            $cacheKey = buildLSCacheKey($swooleReq);
            $cached = $redis->get($cacheKey);
            if ($cached) {
                $cachedData = json_decode($cached, true);
                if ($cachedData && isset($cachedData['headers'], $cachedData['body'], $cachedData['status'])) {
                    $swooleRes->status($cachedData['status']);
                    foreach ($cachedData['headers'] as $k => $v) {
                        $swooleRes->header($k, $v);
                    }
                    $swooleRes->header('X-Swoole-LSCache', 'HIT');
                    $swooleRes->end($cachedData['body']);
                    logRequest($swooleReq, $cachedData['status'], microtime(true) - $startTime);
                    return; // 结束执行！不再启动 Flarum
                }
            }
        } catch (\Throwable $e) {
            // 静默忽略 Redis 读取报错，降级走 Flarum 处理
        }
    }
    // ----------------------------------------------------------
    // 静态文件零拷贝放行（完全绕过 Flarum 中间件管道）
    // Swoole 的 sendfile() 底层调用 Linux sendfile(2) 系统调用，
    // 文件内容直接从内核页缓存传输到 Socket，零用户态拷贝，极速。
    // ----------------------------------------------------------
    $requestUri = $swooleReq->server['request_uri'] ?? '/';
    if (str_starts_with($requestUri, '/assets/')) {
        $filePath = __DIR__ . '/public' . $requestUri;
        // 安全检查：防止路径穿越（path traversal）攻击
        $realPath = realpath($filePath);
        $assetDir = realpath(__DIR__ . '/public/assets');
        if ($realPath !== false && str_starts_with($realPath, $assetDir) && is_file($realPath)) {
            $swooleRes->sendfile($realPath);
            logRequest($swooleReq, 200, microtime(true) - $startTime);
            return;
        }
    }
    // ----------------------------------------------------------
    // 协程 Context 隔离
    // 背景：SWOOLE_HOOK_ALL 让同一个 Worker 进程可以并发处理多个请求（多协程）。
    // 每个协程都有自己独立的 Context，在此处存储请求级别的元数据，
    // 避免协程间数据互串（类似 PHP-FPM 的每个进程独立，但更轻量）。
    // ----------------------------------------------------------
    $ctx = \Swoole\Coroutine::getContext();
    $ctx['request_id'] = uniqid('req_', true);
    $ctx['worker_id']  = $GLOBALS['flarum_worker_id'] ?? 0;
    // ----------------------------------------------------------
    // [沙箱] 清理上一个协程在视图引擎单例上残留的错误变量
    // 注意：在 Swoole 多协程环境下，同一 Worker 的不同协程共享容器单例，
    // 因此每个请求开始前都必须清理，而不是依赖进程隔离。
    // ----------------------------------------------------------
    $container = $GLOBALS['flarum_container'];
    try {
        if ($container->bound('view')) {
            $view = $container->make('view');
            $ref  = new \ReflectionProperty($view, 'shared');
            $ref->setAccessible(true);
            $shared = $ref->getValue($view);
            unset($shared['errors']);
            $ref->setValue($view, $shared);
        }
    } catch (\Throwable $ignored) {}
    // ----------------------------------------------------------
    // 将 Swoole Request 转换为 PSR-7 ServerRequest
    // ----------------------------------------------------------
    try {
        $psrRequest = buildPsr7Request($swooleReq);
    } catch (\Throwable $e) {
        $swooleRes->status(400);
        $swooleRes->end('Bad Request: ' . $e->getMessage());
        return;
    }
    // ----------------------------------------------------------
    // 交给 Flarum 的 PSR-15 中间件管道处理
    // ----------------------------------------------------------
    try {
        $psrResponse = $GLOBALS['flarum_handler']->handle($psrRequest);
    } catch (\Throwable $e) {
        error_log('[Flarum-Swoole] 未捕获异常: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        $swooleRes->status(500);
        $swooleRes->end('Internal Server Error');
        // 写访问日志（异常情况）
        logRequest($swooleReq, 500, microtime(true) - $startTime);
        return;
    }
    // ----------------------------------------------------------
    // 将 PSR-7 Response 写回 Swoole Response
    // ----------------------------------------------------------
    emitResponse($psrResponse, $swooleRes);
    // 写访问日志（正常情况）
    logRequest($swooleReq, $psrResponse->getStatusCode(), microtime(true) - $startTime);
    // ----------------------------------------------------------
    // LSCache 兼容层：分析 Flarum 响应头，写入缓存或执行清理
    // ----------------------------------------------------------
    if (isset($GLOBALS['swoole_redis'])) {
        try {
            /** @var \Predis\Client $redis */
            $redis = $GLOBALS['swoole_redis'];
            
            // 1. 处理发帖/删帖导致的精准清除 (Purge)
            $purgeHeader = $psrResponse->getHeaderLine('X-LiteSpeed-Purge');
            if ($purgeHeader) {
                $purges = array_map('trim', explode(',', $purgeHeader));
                $keysToDelete = [];
                foreach ($purges as $purgeObj) {
                    if ($purgeObj === '*') {
                        // 清除全站缓存
                        $keys = $redis->keys('lscache:page:*');
                        if (!empty($keys)) $keysToDelete = array_merge($keysToDelete, $keys);
                    } elseif (str_starts_with($purgeObj, 'tag=')) {
                        $tag = substr($purgeObj, 4);
                        // 获取这个 tag 对应的所有页面 cacheKey
                        $pageKeys = $redis->smembers("lscache:tag:{$tag}");
                        if (!empty($pageKeys)) {
                            $keysToDelete = array_merge($keysToDelete, $pageKeys);
                            $redis->del("lscache:tag:{$tag}"); // 删掉这个 tag 索引集合
                        }
                    }
                }
                if (!empty($keysToDelete)) {
                    // 执行删除
                    $redis->del(array_unique($keysToDelete));
                }
            }
            $statusCode = $psrResponse->getStatusCode();
            
            // ----------------------------------------------------------
            // 处理页面缓存写入
            // ----------------------------------------------------------
            $cacheControl = $psrResponse->getHeaderLine('X-LiteSpeed-Cache-Control');

            // 只有当网关判定为游客，并且 Flarum 也允许缓存时，才写入 Redis
            if ($isGuest && in_array($method, ['GET', 'HEAD']) && str_contains($cacheControl, 'public')) {
                $body = $psrResponse->getBody();
                $body->rewind();
                
                $ttl = 604800; // 默认 7 天
                if (preg_match('/max-age=(\d+)/', $cacheControl, $m)) {
                    $ttl = (int)$m[1];
                }
                $storeData = [
                    'status' => $psrResponse->getStatusCode(),
                    'headers' => [],
                    'body' => $body->getContents()
                ];
                
                // 保存 headers 时去掉 LSCache 私有头
                foreach ($psrResponse->getHeaders() as $name => $values) {
                    $lName = strtolower($name);
                    if (!in_array($lName, ['set-cookie', 'x-litespeed-cache-control', 'x-litespeed-tag', 'x-litespeed-purge'])) {
                        $storeData['headers'][$name] = implode(', ', $values);
                    }
                }
                $cacheKey = buildLSCacheKey($swooleReq);
                // 写入 Redis
                $redis->setex($cacheKey, $ttl, json_encode($storeData, JSON_UNESCAPED_UNICODE));
                // 记录页面与 Tag 的映射关系，为了将来被精准 Purge 删掉
                $tagHeader = $psrResponse->getHeaderLine('X-LiteSpeed-Tag');
                if ($tagHeader) {
                    $tags = array_map('trim', explode(',', $tagHeader));
                    foreach ($tags as $tag) {
                        if ($tag !== '') {
                            $redis->sadd("lscache:tag:{$tag}", $cacheKey);
                            $redis->expire("lscache:tag:{$tag}", $ttl); // tag 集合也设个过期时间，防止死数据泄漏内存
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            echo "\n==================== REDIS LSCache 报错详情 ====================\n";
            echo "[LSCache] Redis 写入/清理异常: " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
            echo "===============================================================\n\n";
        }
    }
    // ----------------------------------------------------------
    // [健康检查] 内存水位监控与 Worker 平滑自毁
    // ----------------------------------------------------------
    // 如果刚刚经历了 LESS 编译等重度操作，Zend 引擎的内存碎片化会严重拖垮弱单核的
    // Cache 命中率。设定一个健康红线（如 128MB），超过此红线，当前 Worker 在处理完
    // 该请求后主动退出。Swoole 的 Manager 会在毫秒级内自动拉起一个干净的新 Worker。
    if (memory_get_usage() > 128 * 1024 * 1024) {
        echo "[Worker #{$GLOBALS['flarum_worker_id']}] 内存超过 128MB，触发平滑重启，清理碎片...\n";
        global $server;
        if ($server instanceof \Swoole\Server) {
            // 用协程延迟一点停止，确保当前响应网络发送完毕
            \Swoole\Coroutine\System::sleep(0.1);
            $server->stop($GLOBALS['flarum_worker_id']);
        }
    }
});
// ============================================================
// 工具函数
// ============================================================
/**
 * 构建 LSCache 的缓存键 (实现 Cache-Vary 和 Drop QS)
 */
function buildLSCacheKey(SwooleRequest $req): string
{
    $host = $req->header['host'] ?? 'localhost';
    $uri  = $req->server['request_uri'] ?? '/';
    
    // 1. 处理 Drop QS (忽略无用参数)
    // 根据 acpl-lscache 的默认设置，移除这些不影响页面内容的追踪参数
    $dropQs = ['fbclid', 'gclid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', '_ga'];
    $queryParams = $req->get ?? [];
    foreach ($dropQs as $drop) {
        unset($queryParams[$drop]);
    }
    
    // 重新拼接 QueryString，保证顺序一致性（ksort）防止同样参数不同顺序导致缓存未命中
    ksort($queryParams);
    $qs = http_build_query($queryParams);
    
    // 2. 处理 Cache-Vary (缓存隔离)
    // 根据插件配置，缓存必须根据 locale 和 flarum_lscache_vary 隔离
    $locale = $req->cookie['locale'] ?? 'default';
    $vary = $req->cookie['flarum_lscache_vary'] ?? 'none';
    
    // 对于 Flarum，每个登录用户的 flarum_remember 都是唯一的。
    // 如果插件让连登录用户也缓存，那就意味着每个用户有一个私有缓存。
    // 我们为了极致性能，继续保持“全局拦截”，但加上 cookie 变体。
    $remember = $req->cookie['flarum_remember'] ?? 'guest';
    
    // 拼接成最终的特征串并 MD5
    $signature = "host={$host}&uri={$uri}&qs={$qs}&locale={$locale}&vary={$vary}&remember={$remember}";
    return 'lscache:page:' . md5($signature);
}

/**
 * 将 Swoole Request 转换为 Laminas PSR-7 ServerRequest
 *
 * 卡死通常发生在这里：Stream 必须 rewind，headers 必须是数组形式
 */
function buildPsr7Request(SwooleRequest $req): ServerRequest
{
    $method  = strtoupper($req->server['request_method'] ?? 'GET');
    $uri     = $req->server['request_uri'] ?? '/';
    // 拼接完整 URI（含查询字符串）
    if (!empty($req->server['query_string'])) {
        $uri .= '?' . $req->server['query_string'];
    }
    $headers = $req->header ?? [];
    $host    = $headers['host'] ?? 'localhost';
    $scheme  = $headers['x-forwarded-proto'] ?? 'http';
    $psrUri  = new Uri($scheme . '://' . $host . $uri);
    // 解析 Cookie
    $cookies = $req->cookie ?? [];
    // 解析 QueryString
    $queryParams = $req->get ?? [];
    // 构建 Body 流（关键：必须 rewind 否则 Flarum 读不到 body，会在反序列化时卡死）
    $rawBody = $req->rawContent() ?: '';
    $stream  = new Stream('php://temp', 'wb+');
    if ($rawBody !== '') {
        $stream->write($rawBody);
        $stream->rewind(); // ← 这是导致卡死的最常见遗漏点
    }
    // Header 规整化：Swoole 的 header value 是字符串，PSR-7 要求是数组
    $psrHeaders = [];
    foreach ($headers as $name => $value) {
        $psrHeaders[$name] = [$value]; // 强制包装为数组
    }
    // Server Params（$_SERVER 等价物）
    $serverParams = array_change_key_case($req->server ?? [], CASE_UPPER);
    $serverParams['HTTP_HOST']    = $host;
    $serverParams['REMOTE_ADDR']  = $req->server['remote_addr'] ?? '127.0.0.1';
    // 如果经过 Nginx 反代，从 X-Forwarded-For 中取真实 IP
    if (isset($headers['x-forwarded-for'])) {
        $serverParams['REMOTE_ADDR'] = trim(explode(',', $headers['x-forwarded-for'])[0]);
    }
    // 上传文件（Flarum 头像上传等接口需要此处理）
    $uploadedFiles = buildUploadedFiles($req->files ?? []);
    return new ServerRequest(
        $serverParams,
        $uploadedFiles,
        $psrUri,
        $method,
        $stream,
        $psrHeaders,
        $cookies,
        $queryParams
    );
}
/**
 * 将 Swoole 的文件上传数组转换为 PSR-7 UploadedFile 对象（支持多维数组/批量上传）
 */
function buildUploadedFiles(array $files): array
{
    $uploaded = [];
    foreach ($files as $key => $file) {
        if (is_array($file)) {
            if (isset($file['tmp_name'])) {
                // 单个文件结构
                $uploaded[$key] = new \Laminas\Diactoros\UploadedFile(
                    $file['tmp_name'],
                    $file['size']    ?? 0,
                    $file['error']   ?? UPLOAD_ERR_OK,
                    $file['name']    ?? null,
                    $file['type']    ?? null
                );
            } else {
                // 嵌套数组 (如 name="files[]")
                $uploaded[$key] = buildUploadedFiles($file);
            }
        }
    }
    return $uploaded;
}
/**
 * 将 PSR-7 Response 写入 Swoole Response 并发送
 *
 * 注意：Set-Cookie 必须逐条写入，不能合并为一个 header
 */
function emitResponse(PsrResponse $psrResponse, SwooleResponse $swooleRes): void
{
    $swooleRes->status($psrResponse->getStatusCode());
    foreach ($psrResponse->getHeaders() as $name => $values) {
        $lowerName = strtolower($name);
        if ($lowerName === 'set-cookie') {
            // Set-Cookie 必须逐条写，Swoole 的 header() 方法第三个参数控制是否追加而非覆盖
            foreach ($values as $cookie) {
                $swooleRes->header('Set-Cookie', $cookie, false);
            }
        } else {
            // 其他 header 合并写入
            $swooleRes->header($name, implode(', ', $values));
        }
    }
    // 获取响应体（关键：必须 rewind 后读取，否则得到空字符串）
    $body = $psrResponse->getBody();
    $body->rewind(); // ← 第二个卡死高发点
    $content = $body->getContents();
    $swooleRes->end($content);
}
/**
 * 打印访问日志（仿 Nginx Combined Log 格式）
 */
function logRequest(SwooleRequest $req, int $statusCode, float $durationSec): void
{
    $workerId = $GLOBALS['flarum_worker_id'] ?? '?';
    $method   = $req->server['request_method'] ?? 'GET';
    $uri      = $req->server['request_uri']    ?? '/';
    $qs       = $req->server['query_string']   ?? '';
    $fullUri  = $qs ? "{$uri}?{$qs}" : $uri;
    $ip       = $req->header['x-forwarded-for']
                ?? $req->server['remote_addr']
                ?? '-';
    $durationMs = number_format($durationSec * 1000, 2);
    $time = date('d/M/Y:H:i:s O');
    echo "[{$time}] [W#{$workerId}] {$ip} \"{$method} {$fullUri}\" {$statusCode} {$durationMs}ms\n";
}
// ============================================================
// 启动服务器（进入事件循环，阻塞在此行）
// ============================================================
echo "Flarum Swoole Worker 启动中...\n";
echo "监听地址: http://{$host}:{$port}\n";
echo "Worker 数量: {$workerCount} (= CPU 核心数)\n";
echo "协程 Hook: SWOOLE_HOOK_ALL (PDO 自动协程化)\n";
echo "---\n";
$server->start();
