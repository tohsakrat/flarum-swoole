<?php
/**
* Flarum Swoole Coroutine Worker (协程版)
*
* 基于 Swoole 的 Flarum 常驻内存入口
* 开启了一键协程化，并使用 Context Proxy 解决了单例污染问题。
*/
declare(strict_types=1);

// ============================================================
// ★★★ 用户配置区 —— 只需修改这里 ★★★
// ============================================================

/** 监听地址：Unix Socket 路径（由 Nginx 反代）或 TCP 地址（如 '0.0.0.0'） */
const SWOOLE_LISTEN_HOST = '/tmp/flarum.sock';

/** Worker 进程数量（建议 = 并发页面 API 请求数 × 1.5，通常 6~10） */
const SWOOLE_WORKER_COUNT = 8;

/** PHP 内存上限（峰值在清除缓存触发 LESS 编译时可达 ~1GB） */
const PHP_MEMORY_LIMIT = '1536M';

/** 单 Worker 内存水位（超过后平滑重启以清理堆碎片，单位：字节） */
const WORKER_MEMORY_WATERMARK = 256 * 1024 * 1024; // 256 MB

/** Worker 定时轮换间隔（防止长期运行内存碎片化，单位：小时） */
const WORKER_RECYCLE_HOURS = 0.5;

/** DB 心跳间隔（防止 MySQL wait_timeout 断连，单位：小时） */
const DB_HEARTBEAT_HOURS = 1;

/** DB / Redis 协程连接池大小（每个 Worker 持有的最大连接数） */
const POOL_SIZE = 40;

/** LSCache 功能开关（true = 启用游客缓存加速，false = 禁用） */
const LSCACHE_ENABLED = true;

/** 默认缓存 TTL（秒），Flarum 的 X-LiteSpeed-Cache-Control max-age 优先 */
const LSCACHE_DEFAULT_TTL = 604800; // 7 天

/**
 * WorkerCache: 进程级内存缓存，利用 Swoole worker 长驻特性。
 * 同一 worker 内的所有请求共享，TTL 过期后自动刷新。
 * 注意：8 个 worker 各自独立，不跨进程共享，也无实时失效。
 * 仅适合变化极慢的数据（token、设置等）。
 */
class WorkerCache {
    private static array $store = [];

    public static function get(string $key): mixed {
        $item = self::$store[$key] ?? null;
        if ($item !== null && $item['exp'] > time()) {
            return $item['val'];
        }
        unset(self::$store[$key]);
        return null;
    }

    public static function set(string $key, mixed $val, int $ttl = 60): void {
        self::$store[$key] = ['val' => $val, 'exp' => time() + $ttl];
    }

    /** 主动失效（如登出时调用） */
    public static function delete(string $key): void {
        unset(self::$store[$key]);
    }
}

/** 协程并发序列化开关（true = 启用 WaitGroup 并发，对 Admin 面板效果显著） */
const COROUTINE_SERIALIZER_ENABLED = true;

/** 
 * 日志级别：
 * 'info'  - 只打印启停信息和访问日志
 * 'debug' - 额外打印数据库请求中【未合并】(BYPASSED) 的 SQL 语句，用于分析优化空间
 */
const LOG_LEVEL = 'info';

// ============================================================

// ============================================================
// 启动检查与一键协程化
// ============================================================
if (!extension_loaded('swoole')) {
fwrite(STDERR, "[ERROR] Swoole 扩展未安装。请执行: pecl install swoole\n");
exit(1);
}

// 核心：开启一键协程化 Hook（拦截 PDO, Redis, cURL, 文件 IO 等）
\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$composerLoader = require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/AutoBatchingDataLoader.php';

// 协程并发序列化引擎：并发化 Collection::toArray()（真正的计算瓶颈）
// toArray() 内每个 resource->toArray() 调用 serializer->getAttributes/getRelationships，
// 这才是耗 CPU / 触发 DB 的地方；getResources() 只是返回已有对象，不需要改。
if (COROUTINE_SERIALIZER_ENABLED) {
    $collectionFile = $composerLoader->findFile('Tobscure\JsonApi\Collection');
    if (!$collectionFile || !file_exists($collectionFile)) {
        echo "[WARNING] 无法定位 Tobscure\\JsonApi\\Collection，跳过协程序列化注入。\n";
    } elseif (class_exists('Tobscure\JsonApi\Collection', false)) {
        echo "[Boot] Tobscure\\JsonApi\\Collection 已被提前加载，跳过注入。\n";
    } else {
        $src = file_get_contents($collectionFile);

        // 定位并替换 toArray()（协程并行化每个 resource->toArray()）
        if (preg_match('/public\s+function\s+toArray\s*\(\s*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $sigEnd    = $m[0][1] + strlen($m[0][0]);
            $depth     = 1;
            $pos       = $sigEnd;
            $len       = strlen($src);
            while ($pos < $len && $depth > 0) {
                if ($src[$pos] === '{') $depth++;
                elseif ($src[$pos] === '}') $depth--;
                $pos++;
            }
            $lineStart = strrpos(substr($src, 0, $m[0][1]), "\n");

            $newMethod = '
    public function toArray()
    {
        // 协程环境 + 多条目时并行计算每个 resource->toArray()
        if (\Swoole\Coroutine::getCid() > 0 && count($this->resources) > 1) {
            $wg         = new \Swoole\Coroutine\WaitGroup();
            $results    = [];
            $parentCtx  = \Swoole\Coroutine::getContext();
            $viewShared = $parentCtx["view_shared"] ?? [];
            foreach ($this->resources as $idx => $resource) {
                $wg->add();
                \Swoole\Coroutine\go(function () use ($wg, $resource, $idx, &$results, $viewShared) {
                    $ctx = \Swoole\Coroutine::getContext();
                    $ctx["view_shared"] = $viewShared;
                    try { 
                        $results[$idx] = $resource->toArray(); 
                    }
                    finally { $wg->done(); }
                });
            }
            $wg->wait();
            ksort($results);
            return array_values($results);
        }
        // 非协程或单条目时串行降级
        return array_map(function ($resource) {
            return $resource->toArray();
        }, $this->resources);
    }';

            $patched = substr($src, 0, $lineStart + 1) . $newMethod . "\n" . substr($src, $pos);
        } else {
            $patched = $src; // toArray() 不存在则不修改
        }

        $code = preg_replace('/^.*?<\?php\s*/is', '', $patched);
        try {
            eval($code);
            echo "[Boot] 协程序列化引擎注入成功（toArray 并发版）。\n";
        } catch (\Throwable $e) {
            echo "[Boot] ⚠️ 协程序列化注入失败: " . $e->getMessage() . "\n";
        }
    }
}

use Swoole\Http\Server;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface as PsrResponse;

// ============================================================
// 视图引擎魔术代理 (解决 Flarum 视图 $shared 串号问题)
// ============================================================
class CoroutineViewProxy implements \Illuminate\Contracts\View\Factory {
protected $realView;

public function __construct($realView) {
$this->realView = $realView;
}

public function exists($view) {
return $this->realView->exists($view);
}

public function file($path, $data = [], $mergeData = []) {
$ctx = \Swoole\Coroutine::getContext();
$shared = $ctx ? ($ctx['view_shared'] ?? []) : [];
return $this->realView->file($path, array_merge($shared, $data), $mergeData);
}

public function make($view, $data = [], $mergeData = []) {
$ctx = \Swoole\Coroutine::getContext();
$shared = $ctx ? ($ctx['view_shared'] ?? []) : [];
return $this->realView->make($view, array_merge($shared, $data), $mergeData);
}

public function share($key, $value = null) {
$ctx = \Swoole\Coroutine::getContext();
if ($ctx) {
if (!isset($ctx['view_shared'])) {
$ctx['view_shared'] = [];
}
if (is_array($key)) {
$ctx['view_shared'] = array_merge($ctx['view_shared'], $key);
} else {
$ctx['view_shared'][$key] = $value;
}
}
return $value;
}

public function composer($views, $callback) {
return $this->realView->composer($views, $callback);
}

public function creator($views, $callback) {
return $this->realView->creator($views, $callback);
}

public function addNamespace($namespace, $hints) {
return $this->realView->addNamespace($namespace, $hints);
}

public function replaceNamespace($namespace, $hints) {
return $this->realView->replaceNamespace($namespace, $hints);
}

public function __call($method, $args) {
return $this->realView->$method(...$args);
}
}

class CoroutineDbProxy implements \Illuminate\Database\ConnectionResolverInterface {
protected $channel;
protected $fallbackManager = null;

    public function __construct($original, int $size = 20) {
        $this->channel = new \Swoole\Coroutine\Channel($size);
        $container = $GLOBALS['flarum_container'];
        
        for ($i = 0; $i < $size; $i++) {
            // 彻底解决 Swoole Socket 跨协程复用问题：
            // 不能简单 clone $original，因为 PDO 是底层 Socket，clone 只是浅拷贝引用！
            // 必须通过 ConnectionFactory 重新创建真正的、物理隔离的全新数据库连接。
            $factory = new \Illuminate\Database\Connectors\ConnectionFactory($container);
            $dbConfig = $container->make('flarum.config')['database'];
            
            // 极其关键：必须强制禁用 PDO 持久连接（PDO::ATTR_PERSISTENT）。
            // 否则 PHP 底层会复用相同凭据的 C 级 Socket，导致即使创建了多个 Connection 对象，
            // 它们底层也依然共用同一个 Socket，从而引发 Swoole 跨协程 Socket 污染！
            if (!isset($dbConfig['options'])) {
                $dbConfig['options'] = [];
            }
            $dbConfig['options'][\PDO::ATTR_PERSISTENT] = false;
            
            // 创建全新连接
            $newConnection = $factory->make($dbConfig, 'flarum');
            
            // 将池中的连接也升级为 AutoBatchingMySqlConnection，以便激活请求合并日志和功能
            if ($newConnection instanceof \Illuminate\Database\MySqlConnection) {
                $batchingConn = new AutoBatchingMySqlConnection(
                    $newConnection->getPdo(),
                    $newConnection->getDatabaseName(),
                    $newConnection->getTablePrefix(),
                    $newConnection->getConfig()
                );
                
                // 仅拷贝非空的必要属性（事件分发器）
                // Grammar 和 PostProcessor 在 parent::__construct 中已由 Laravel 自动按配置初始化，无需画蛇添足
                if ($dispatcher = $newConnection->getEventDispatcher()) {
                    $batchingConn->setEventDispatcher($dispatcher);
                }
                
                $newConnection = $batchingConn;
            }
            
            // 包装成 Flarum 所需的 ConnectionResolver
            $newResolver = new \Illuminate\Database\ConnectionResolver([
                'flarum' => $newConnection
            ]);
            $newResolver->setDefaultConnection('flarum');
            
            $this->channel->push($newResolver);
        }
    }

protected function getManager() {
    $cid = \Swoole\Coroutine::getCid();
    if ($cid < 0) {
        // 非协程环境（workerStart 阶段）：直接创建一个临时连接用于预热/心跳，不占用池
        // 这样避免了 fallbackManager 从池里永久借走一个连接不归还的泄漏问题
        if (!$this->fallbackManager) {
            $container = $GLOBALS['flarum_container'];
            $factory = new \Illuminate\Database\Connectors\ConnectionFactory($container);
            $dbConfig = $container->make('flarum.config')['database'];
            if (!isset($dbConfig['options'])) { $dbConfig['options'] = []; }
            $dbConfig['options'][\PDO::ATTR_PERSISTENT] = false;
            $conn = $factory->make($dbConfig, 'flarum');
            $resolver = new \Illuminate\Database\ConnectionResolver(['flarum' => $conn]);
            $resolver->setDefaultConnection('flarum');
            $this->fallbackManager = $resolver;
        }
        return $this->fallbackManager;
    }

    $ctx = \Swoole\Coroutine::getContext();
    if (!isset($ctx['db_manager'])) {
        // Fix #2: pop() 必须有超时，防止池耗尽时协程永久挂起
        $resolver = $this->channel->pop(5.0);
        if ($resolver === false) {
            throw new \RuntimeException('[CoroutineDbProxy] DB 连接池已耗尽，等待超时 (5s)');
        }

        // 断线检测：MySQL 重启后池里的连接是死 socket，必须在使用前验证。
        // 用 SELECT 1 ping 一下；失败则丢弃旧连接，重建新连接放入 ctx。
        $conn = $resolver->connection();
        try {
            $conn->getPdo()->query('SELECT 1');
        } catch (\Throwable $e) {
            // 旧连接已死，重建一条新连接
            try {
                $container = $GLOBALS['flarum_container'];
                $factory   = new \Illuminate\Database\Connectors\ConnectionFactory($container);
                $dbConfig  = $container->make('flarum.config')['database'];
                if (!isset($dbConfig['options'])) { $dbConfig['options'] = []; }
                $dbConfig['options'][\PDO::ATTR_PERSISTENT] = false;
                $newConn = $factory->make($dbConfig, 'flarum');
                if ($newConn instanceof \Illuminate\Database\MySqlConnection) {
                    $batching = new AutoBatchingMySqlConnection(
                        $newConn->getPdo(),
                        $newConn->getDatabaseName(),
                        $newConn->getTablePrefix(),
                        $newConn->getConfig()
                    );
                    if ($dispatcher = $newConn->getEventDispatcher()) {
                        $batching->setEventDispatcher($dispatcher);
                    }
                    $newConn = $batching;
                }
                $resolver = new \Illuminate\Database\ConnectionResolver(['flarum' => $newConn]);
                $resolver->setDefaultConnection('flarum');
            } catch (\Throwable $rebuildErr) {
                // 重建也失败（MySQL 可能还在启动中），把旧 resolver 还回池，抛出异常让调用方处理
                $this->channel->push($resolver);
                throw new \RuntimeException('[CoroutineDbProxy] DB 连接断开且重建失败: ' . $rebuildErr->getMessage());
            }
        }

        $ctx['db_manager'] = $resolver;
        \Swoole\Coroutine\defer(function() use ($ctx) {
            if (isset($ctx['db_manager'])) {
                $this->channel->push($ctx['db_manager']);
            }
        });
    }
    return $ctx['db_manager'];
}

public function getRealConnection($name = null) {
    return $this->getManager()->connection($name);
}

public function connection($name = null) { 
    // 必须直接返回 Illuminate\Database\Connection 物理单例！
    // 否则 staudenmeir/eloquent-eager-limit 等第三方库的强类型检测 (TypeError) 会直接阻断程序运行！
    // 串号问题已被我们在更底层的 PDO 替换机制（核弹级 PDO 反射注入）中完美解决！
    return $this->getRealConnection($name);
}
public function getDefaultConnection() { return $this->getManager()->getDefaultConnection(); }
public function setDefaultConnection($name) { $this->getManager()->setDefaultConnection($name); }
public function __call($method, $args) { return $this->getManager()->$method(...$args); }
}

class CoroutineRedisProxy implements \Illuminate\Contracts\Redis\Factory {
private $originalManager;
private array $rawConfigs = [];
private array $pools = [];
private int $poolSize;

public function __construct($manager, int $poolSize = 20) {
$this->originalManager = $manager;
$this->poolSize = $poolSize;

try {
$ref = new \ReflectionClass($manager);
if ($ref->hasProperty('config')) {
$configProp = $ref->getProperty('config');
$configProp->setAccessible(true);
$managerConfig = $configProp->getValue($manager);
$connections = $managerConfig['connections'] ?? $managerConfig;

// 处理 Flarum 可能存在的扁平化 Redis 配置数组结构
if (isset($connections['host'])) {
$this->rawConfigs['default'] = $connections;
} else {
foreach ($connections as $connName => $connConfig) {
if (is_array($connConfig)) {
$this->rawConfigs[$connName] = $connConfig;
}
}
}

$default = $managerConfig['default'] ?? 'default';
if (isset($this->rawConfigs[$default]) && !isset($this->rawConfigs['default'])) {
$this->rawConfigs['default'] = $this->rawConfigs[$default];
}
}
} catch (\Throwable $e) {}
}

private function makeFreshPredisConnection(string $name): \Illuminate\Redis\Connections\PredisConnection {
$config = $this->rawConfigs[$name] ?? $this->rawConfigs['default'] ?? [];

$host = $config['host'] ?? '127.0.0.1';
$port = $config['port'] ?? 6379;
$password = $config['password'] ?? null;
$database = $config['database'] ?? 0;
$timeout = $config['timeout'] ?? 2.0;
$prefix = $config['prefix'] ?? '';

$params = ['host' => $host, 'port' => (int)$port, 'database' => (int)$database, 'timeout' => (float)$timeout];
if ($password !== null && $password !== '') {
$params['password'] = $password;
}
if ($prefix !== '') {
$params['prefix'] = $prefix;
}

$client = new \Predis\Client($params, ['exceptions' => true]);
return new \Illuminate\Redis\Connections\PredisConnection($client);
}

private function getPool(string $name): \Swoole\Coroutine\Channel {
if (!isset($this->pools[$name])) {
$this->pools[$name] = new \Swoole\Coroutine\Channel($this->poolSize);
for ($i = 0; $i < $this->poolSize; $i++) {
$this->pools[$name]->push($this->makeFreshPredisConnection($name));
}
}
return $this->pools[$name];
}

public function connection($name = null) {
$name = $name ?? 'default';
$cid = \Swoole\Coroutine::getCid();

if ($cid < 0) {
return $this->originalManager->connection($name);
}

$ctx = \Swoole\Coroutine::getContext();
$key = 'predis_conn_' . $name;

if (!isset($ctx[$key])) {
if (!empty($this->rawConfigs)) {
$pool = $this->getPool($name);
$ctx[$key] = $pool->pop(); // 等待获取可用连接

// 协程结束时归还连接
\Swoole\Coroutine\defer(function() use ($pool, $ctx, $key) {
if (isset($ctx[$key])) {
$pool->push($ctx[$key]);
}
});
} else {
$ctx[$key] = $this->originalManager->connection($name);
}
}

return $ctx[$key];
}

public function __call($method, $args) {
return $this->connection()->$method(...$args);
}
}

// ============================================================
// 服务器配置
// ============================================================
$host = SWOOLE_LISTEN_HOST;
$port = 0;
$workerCount = SWOOLE_WORKER_COUNT;

$pidFile = '/tmp/flarum-swoole.pid';
$action = $argv[1] ?? 'start';

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

$server = new Server($host, 0, SWOOLE_BASE, SWOOLE_SOCK_UNIX_STREAM);

$server->set([
'worker_num' => $workerCount,

'open_cpu_affinity' => true, // 开启 CPU 亲和性设置
'task_worker_num' => 0, // 未来可调整为 4 开启异步任务处理
'max_request' => 0,
'reload_async' => true,
'max_wait_time' => 60,
'enable_reuse_port' => false,
'http_compression' => false,
'enable_coroutine' => true, // <--- 关键：开启请求级别的协程

'open_tcp_nodelay' => true,
'open_tcp_keepalive' => true,
'tcp_keepidle' => 60,
'tcp_keepinterval' => 10,
'tcp_keepcount' => 3,

'buffer_output_size' => 16 * 1024 * 1024,
'socket_buffer_size' => 512 * 1024,
'package_max_length' => 50 * 1024 * 1024,

'max_coroutine' => 10000,
'stack_size' => 8 * 1024 * 1024,

'daemonize' => false,
'pid_file' => '/tmp/flarum-swoole.pid',
'log_file' => __DIR__ . '/storage/logs/swoole.log',
'log_level' => SWOOLE_LOG_WARNING,
]);

// ============================================================
// Worker 进程启动
// ============================================================
$server->on('workerStart', function (Server $server, int $workerId) {
ini_set('memory_limit', PHP_MEMORY_LIMIT);

$_SERVER['X-LSCACHE'] = 'on';
$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed Web Server (Swoole Emulator)';

echo "[Worker #{$workerId}] 启动中，正在加载 Flarum 应用...\n";

try {
$site = require __DIR__ . '/site.php';
$app = $site->bootApp();

$gateClass = null;
if (class_exists('SwooleMemoryGate')) {
    $gateClass = 'SwooleMemoryGate';
} else {
    foreach (get_declared_classes() as $class) {
        if (str_ends_with($class, 'SwooleMemoryGate')) {
            $gateClass = $class;
            break;
        }
    }
}

if ($gateClass && property_exists($gateClass, 'permissionsMap')) {
    $map = [];
    foreach (\Flarum\Group\Permission::get() as $p) {
        $map[$p->group_id][] = $p->permission;
    }
    $gateClass::$permissionsMap = $map;
    echo "[Worker #{$workerId}] {$gateClass} 权限表预加载完成。\n";
} else {
    echo "[Worker #{$workerId}] ⚠️ 未找到 SwooleMemoryGate，跳过权限预加载。\n";
}

} catch (\Throwable $e) {
fwrite(STDERR, "[Worker #{$workerId}] Flarum 启动失败: {$e->getMessage()}\n");
fwrite(STDERR, $e->getTraceAsString() . "\n");
$server->stop($workerId);
return;
}

$container = $app->getContainer();
$GLOBALS['flarum_container'] = $container;
$GLOBALS['flarum_worker_id'] = $workerId;
$GLOBALS['flarum_handler']   = $app->getRequestHandler();

// ----------------------------------------------------------
// 拦截并替换视图单例为协程代理 (防止 $shared['errors'] 串号)
// ----------------------------------------------------------
if ($container->bound('view')) {
$realView = $container->make('view');
$container->instance('view', new CoroutineViewProxy($realView));
echo "[Worker #{$workerId}] 视图引擎协程代理挂载成功。\n";
}

// ----------------------------------------------------------
// 拦截并替换 DB 和 Redis 为协程连接池代理
// ----------------------------------------------------------
if ($container->bound('db')) {
    // 在安装代理前先保存原始实例，后续所有对原始连接的操作都用这两个变量，
    // 绝不能在安装代理后再调 $container->make('db') 或 $container->make('flarum.db')，
    // 否则会取到代理对象，导致从连接池 pop 出连接并污染其真实 PDO。
    $originalDb = $container->make('db');       // 原始 DatabaseManager
    $flarumDb   = $container->make('flarum.db'); // 原始底层 Connection 单例

    $dbProxy = new CoroutineDbProxy($originalDb, 20);
    $GLOBALS['coroutine_db_proxy'] = $dbProxy;
    $container->instance('db', $dbProxy);
    \Illuminate\Database\Eloquent\Model::setConnectionResolver($dbProxy);

    // flarum.db 绑定到原始单例，不能绑池连接！
    // 池连接的 PDO 必须是真实 PDO，proxyPdo 只装在原始单例上。
    $container->instance('flarum.db', $flarumDb);
    $container->instance(\Illuminate\Database\ConnectionInterface::class, $flarumDb);

    // proxyPdo：兜底拦截层，用于那些绕过 CoroutineDbProxy 直接持有旧单例引用的代码。
    // proxyPdo::getRealPdo() → 取当前协程 ctx 持有的池连接（真实 PDO）→ 无递归。
    $proxyPdo = new class extends \PDO {
        public function __construct() {}
        private function getRealPdo() {
            if (isset($GLOBALS['coroutine_db_proxy'])) {
                return $GLOBALS['coroutine_db_proxy']->getRealConnection()->getPdo();
            }
            return $GLOBALS['flarum_container']->make('db')->connection()->getPdo();
        }
        public function prepare($statement, $options = []) { return $this->getRealPdo()->prepare($statement, $options ?: []); }
        public function beginTransaction() { return $this->getRealPdo()->beginTransaction(); }
        public function commit() { return $this->getRealPdo()->commit(); }
        public function rollBack() { return $this->getRealPdo()->rollBack(); }
        public function inTransaction() { return $this->getRealPdo()->inTransaction(); }
        public function setAttribute($attribute, $value) { return $this->getRealPdo()->setAttribute($attribute, $value); }
        public function exec($statement) { return $this->getRealPdo()->exec($statement); }
        public function query(...$args) { return $this->getRealPdo()->query(...$args); }
        public function lastInsertId($name = null) { return $this->getRealPdo()->lastInsertId($name); }
        public function errorCode() { return $this->getRealPdo()->errorCode(); }
        public function errorInfo() { return $this->getRealPdo()->errorInfo(); }
        public function getAttribute($attribute) { return $this->getRealPdo()->getAttribute($attribute); }
        public function quote($string, $paramtype = \PDO::PARAM_STR) { return $this->getRealPdo()->quote($string, $paramtype); }
    };

    // 将 proxyPdo 安装到原始单例（$flarumDb 和 $originalDb 内部缓存的连接）
    foreach ([$flarumDb, $originalDb->connection()] as $connToWrap) {
        $r = new \ReflectionClass($connToWrap);
        foreach (['pdo', 'readPdo'] as $prop) {
            if ($r->hasProperty($prop)) {
                $p = $r->getProperty($prop);
                $p->setAccessible(true);
                $p->setValue($connToWrap, $proxyPdo);
            }
        }
    }

    // 替换 DatabaseManager 内部的 ConnectionFactory，确保之后通过 $originalDb 新建的连接
    // 都是 AutoBatchingMySqlConnection，且装有 proxyPdo（因为是通过 $originalDb 路径来的）。
    $dbReflection = new \ReflectionClass($originalDb);
    if ($dbReflection->hasProperty('factory')) {
        $factoryProp = $dbReflection->getProperty('factory');
        $factoryProp->setAccessible(true);
        $originalFactory = $factoryProp->getValue($originalDb);

        $proxyFactory = new class($originalFactory, $proxyPdo) {
            private $factory;
            private $proxyPdo;
            public function __construct($factory, $proxyPdo) {
                $this->factory   = $factory;
                $this->proxyPdo  = $proxyPdo;
            }
            public function make($config, $name = null) {
                $conn = $this->factory->make($config, $name);
                if ($conn instanceof \Illuminate\Database\MySqlConnection) {
                    $newConn = new AutoBatchingMySqlConnection(
                        $conn->getPdo(),
                        $conn->getDatabaseName(),
                        $conn->getTablePrefix(),
                        $conn->getConfig()
                    );
                    if ($dispatcher = $conn->getEventDispatcher()) {
                        $newConn->setEventDispatcher($dispatcher);
                    }
                    $r = new \ReflectionClass($newConn);
                    if ($r->hasProperty('pdo')) {
                        $p = $r->getProperty('pdo');
                        $p->setAccessible(true);
                        $p->setValue($newConn, $this->proxyPdo);
                    }
                    return $newConn;
                }
                return $conn;
            }
            public function __call($method, $args) { return $this->factory->$method(...$args); }
        };

        $factoryProp->setValue($originalDb, $proxyFactory);
    }

    echo "[Worker #{$workerId}] DB 引擎协程池代理挂载成功 (包含 AutoBatching 升级 & PDO 拦截)。\n";
}

// FoF Redis 不在 'redis' 键下注册，而是注册为全限定类名和接口别名。
// 我们需要探测正确的键并进行替换。
$redisKeys = [
'FoF\Redis\Overrides\RedisManager',
'Illuminate\Contracts\Redis\Factory',
'redis'
];
$redisManager = null;
$foundKey = null;

foreach ($redisKeys as $key) {
if ($container->bound($key)) {
try {
$redisManager = $container->make($key);
$foundKey = $key;
break;
} catch (\Throwable $e) {}
}
}

if ($redisManager) {
try {
$redisProxy = new CoroutineRedisProxy($redisManager);

// 检查 rawConfigs 是否提取成功，失败则输出警告
$ref = new \ReflectionClass($redisProxy);
$rcProp = $ref->getProperty('rawConfigs');
$rcProp->setAccessible(true);
$rawCfgs = $rcProp->getValue($redisProxy);
if (empty($rawCfgs)) {
echo "[Worker #{$workerId}] ⚠️ Redis rawConfigs 提取失败，降级为原始 manager（可能存在协程竞争）。\n";
} else {
echo "[Worker #{$workerId}] Redis 协程代理挂载成功（连接名: " . implode(',', array_keys($rawCfgs)) . "）。\n";
}

// 只替换接口和别名绑定，保留具体类绑定以防止强类型注入失败
// （如 FoF\Horizon\AdminContent 构造函数要求 FoF\Redis\Overrides\RedisManager 具体类型）
$interfaceKeysOnly = [
'Illuminate\Contracts\Redis\Factory',
'redis',
];
foreach ($interfaceKeysOnly as $key) {
if ($container->bound($key)) {
$container->instance($key, $redisProxy);
}
}
} catch (\Throwable $e) {
echo "[Worker #{$workerId}] ❌ Redis 代理安装失败: {$e->getMessage()}\n";
}
} else {
echo "[Worker #{$workerId}] ℹ️ 容器中未找到 Redis 服务，跳过代理安装。\n";
}

// 清除由于启动时可能已经实例化的依赖组件，强制它们使用新的 db 和 redis 代理
$container->forgetInstance('cache');
$container->forgetInstance('cache.store');
$container->forgetInstance('session');
$container->forgetInstance('session.store');
$container->forgetInstance('session.handler');
$container->forgetInstance('queue');
$container->forgetInstance('queue.connection');
$container->forgetInstance('flarum.queue.connection');

// 必须清除 Middleware 管道缓存，否则 StartSession 依然持有旧的单例
$container->forgetInstance('flarum.api.middleware');
$container->forgetInstance('flarum.forum.middleware');
$container->forgetInstance('flarum.admin.middleware');
$container->forgetInstance('flarum.api.handler');
$container->forgetInstance('flarum.forum.handler');
$container->forgetInstance('flarum.admin.handler');

// 重新构建 Handler 以让所有的中间件获取到代理后的单例
$GLOBALS['flarum_handler'] = $app->getRequestHandler();

// ----------------------------------------------------------
// LSCache 兼容层：Redis 连接池
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
if ($ref->hasProperty('configuration')) {
$prop = $ref->getProperty('configuration');
$prop->setAccessible(true);
$configObj = $prop->getValue($extender);
if (is_object($configObj)) {
$configRef = new \ReflectionClass($configObj);
if ($configRef->hasProperty('config')) {
$innerProp = $configRef->getProperty('config');
$innerProp->setAccessible(true);
$val = $innerProp->getValue($configObj);
if (is_array($val) && (isset($val['path']) || isset($val['host']) || isset($val['database']))) {
$redisSettings = $val;
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
echo "[Worker #{$workerId}] 致命错误：在 extend.php 中未提取到 FoF\Redis 配置！\n";
throw new \Exception("提取 Redis 配置失败，服务启动终止。");
}

// 注意：在正式的协程版中，这里应该初始化为 Redis 连接池
$swooleRedisPool = new \Swoole\Coroutine\Channel(POOL_SIZE);
for ($i = 0; $i < POOL_SIZE; $i++) {
$r = new \Predis\Client($redisSettings);
$r->connect();
$swooleRedisPool->push($r);
}
$GLOBALS['swoole_redis_pool'] = $swooleRedisPool;

if (isset($redisSettings['session_database'])) {
$sessionSettings = $redisSettings;
$sessionSettings['database'] = $redisSettings['session_database'];
unset($sessionSettings['session_database']);
$swooleSessionRedisPool = new \Swoole\Coroutine\Channel(POOL_SIZE);
for ($i = 0; $i < POOL_SIZE; $i++) {
$r = new \Predis\Client($sessionSettings);
$r->connect();
$swooleSessionRedisPool->push($r);
}
$GLOBALS['swoole_session_redis_pool'] = $swooleSessionRedisPool;
} else {
$GLOBALS['swoole_session_redis_pool'] = $swooleRedisPool;
}

} catch (\Throwable $e) {
echo "[Worker #{$workerId}] LSCache: Redis 连接异常: {$e->getMessage()}\n";
}

// ----------------------------------------------------------
// 提前预热 Flarum 引擎，消除首个真实请求的冷启动开销
// (必须在 DB/Redis 代理池完全初始化之后执行！)
// ----------------------------------------------------------
\Swoole\Coroutine::create(function() use ($workerId) {
    try {
        $warmupUri = new \Laminas\Diactoros\Uri('http://localhost/api/discussions');
        $warmupRequest = new \Laminas\Diactoros\ServerRequest(
            [], [], $warmupUri, 'GET', 'php://temp',
            ['Accept' => ['application/json']]
        );
        
        // 模拟提供 Redis 池连接到上下文，防止内部逻辑报错
        $ctx = \Swoole\Coroutine::getContext();
        if (isset($GLOBALS['swoole_redis_pool'])) {
            $ctx['lscache_redis'] = $GLOBALS['swoole_redis_pool']->pop(1);
            if ($ctx['lscache_redis']) {
                \Swoole\Coroutine\defer(function() use ($ctx) {
                    $GLOBALS['swoole_redis_pool']->push($ctx['lscache_redis']);
                });
            }
        }
        
        $GLOBALS['flarum_handler']->handle($warmupRequest);
        echo "[Worker #{$workerId}] Flarum 引擎预热完毕 (冷启动已消除)。\n";
    } catch (\Throwable $e) {
        echo "[Worker #{$workerId}] ⚠️ 预热遇到错误(通常可忽略): {$e->getMessage()}\n";
    }
});

// ----------------------------------------------------------
// DB 保活定时器
// ----------------------------------------------------------
\Swoole\Timer::tick((int)(DB_HEARTBEAT_HOURS * 3600 * 1000), function () use ($workerId) {
    \Swoole\Coroutine::create(function() use ($workerId) {
        try {
            // 心跳必须在协程里运行，才能从池里正确获取连接
            $GLOBALS['flarum_container']->make('db')->connection()->select('SELECT 1');
        } catch (\Throwable $e) {
            // 心跳失败时静默即可，池中的连接会在下次使用时自动重连
            error_log("[Worker #{$workerId}] DB 心跳失败: " . $e->getMessage());
        }
    });
});

$recycleInterval = (int)(WORKER_RECYCLE_HOURS * 3600 * 1000);
$recycleOffset = $workerId * 180 * 1000;
\Swoole\Timer::after($recycleOffset, function () use ($server, $workerId, $recycleInterval) {
\Swoole\Timer::tick($recycleInterval, function () use ($server, $workerId) {
echo "[Worker #{$workerId}] [" . date('H:i:s') . "] 定时清理碎片...\n";
$server->stop($workerId);
});
});

echo "[Worker #{$workerId}] Flarum 加载成功(协程模式)。\n";
});

// ============================================================
// 处理每个 HTTP 请求
// ============================================================
$server->on('request', function (SwooleRequest $swooleReq, SwooleResponse $swooleRes) use ($server) {
$startTime = microtime(true);
$ctx = \Swoole\Coroutine::getContext();

// 请求结束时自动清理 AutoBatching 状态，防止跨请求污染
\Swoole\Coroutine\defer(function () {
    AutoBatchingDataLoader::cleanup();
});

if (isset($GLOBALS['swoole_redis_pool'])) {
    $conn = $GLOBALS['swoole_redis_pool']->pop(3.0);
    if ($conn !== false) {
        $ctx['lscache_redis'] = $conn;
        \Swoole\Coroutine\defer(function() use ($ctx) {
            $GLOBALS['swoole_redis_pool']->push($ctx['lscache_redis']);
        });
    }
    // pop() 返回 false 说明池已耗尽，降级处理：跳过缓存读取，直接走 Flarum
}

if (isset($GLOBALS['swoole_session_redis_pool'])) {
    $conn = $GLOBALS['swoole_session_redis_pool']->pop(3.0);
    if ($conn !== false) {
        $ctx['lscache_session_redis'] = $conn;
        \Swoole\Coroutine\defer(function() use ($ctx) {
            $GLOBALS['swoole_session_redis_pool']->push($ctx['lscache_session_redis']);
        });
    }
}

$method = strtoupper($swooleReq->server['request_method'] ?? 'GET');

$isGuest = true;
if (isset($swooleReq->header['authorization'])) {
    // API Token 鉴权（直接放行，不走缓存）
    $isGuest = false;
} elseif (!empty($swooleReq->cookie['flarum_remember'])) {
    // Fix #9: flarum_remember 可能已过期，保守处理：检查数据库
    // RememberFromCookie 中间件会在首次携带此 cookie 时写入 session 的 access_token
    // 由于我们此时还没有 session，直接信任 cookie 存在但不走缓存
    $isGuest = false;
} elseif (!empty($swooleReq->cookie['flarum_session']) && isset($ctx['lscache_session_redis'])) {
    $sessionId = $swooleReq->cookie['flarum_session'];
    $redis = $ctx['lscache_session_redis'];

    // Fix #6: fof/redis 使用 CacheBasedSessionHandler，写入 key 就是裸 session ID（无前缀）
    // 值是 PHP serialize() 后的字符串，内容是 Laravel session 数组
    $sessionData = $redis->get($sessionId);

    if ($sessionData) {
        // fof/redis 写入的格式是 s:N:"a:..."; （外层 serialize(string)）
        // 需要先 unserialize 一次拿到内层序列化字符串，再 unserialize 一次拿到 PHP array
        $inner = @unserialize($sessionData);
        if (is_string($inner)) {
            $parsed = @unserialize($inner);
        } else {
            $parsed = $inner; // 兼容某些版本直接存 array 的情况
        }

        // Flarum 在用户登录时（SessionAuthenticator::logIn）会把 access_token 写入 session
        // 游客 session 只有 _token（CSRF）和 _flash，没有 access_token
        $accessToken = is_array($parsed) ? ($parsed['access_token'] ?? null) : null;

        if ($accessToken) {
            // WorkerCache: access_token 查询结果缓存 60 秒。
            // token 只在登录/登出时变化，60s TTL 完全安全，每个 worker 节省一次 DB 查询。
            $cacheKey = 'at:' . $accessToken;
            $tokenRow = WorkerCache::get($cacheKey);
            if ($tokenRow === null) {
                try {
                    $db = $GLOBALS['flarum_container']->make('db')->connection();
                    $tokenRow = $db->table('access_tokens')->where('token', $accessToken)->first();
                    // false 表示「查过了，不存在」，区别于「还没查过」的 null
                    WorkerCache::set($cacheKey, $tokenRow ?? false, 60);
                } catch (\Throwable $e) {
                    $tokenRow = false;
                    error_log('[Worker] Session DB Check Error: ' . $e->getMessage());
                }
            }
            if ($tokenRow && !empty($tokenRow->user_id)) {
                $isGuest = false;
                $ctx['prefetched_access_token'] = $tokenRow;
            }
        } elseif (is_array($parsed) && isset($parsed['_token'])) {
            // session 存在但没有 access_token → 这是游客的 session（只有 CSRF token）
            // $isGuest 保持 true，正确走缓存
        } else {
            // 无法解析 session → 保守处理，不走缓存
            $isGuest = false;
        }
    }
}

if (LSCACHE_ENABLED && $isGuest && in_array($method, ['GET', 'HEAD']) && isset($ctx['lscache_redis'])) {
try {
$redis = $ctx['lscache_redis'];
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
return;
}
}
} catch (\Throwable $e) {}
}

$requestUri = $swooleReq->server['request_uri'] ?? '/';
if (str_starts_with($requestUri, '/assets/')) {
$filePath = __DIR__ . '/public' . $requestUri;
$realPath = realpath($filePath);
$assetDir = realpath(__DIR__ . '/public/assets');
if ($realPath !== false && str_starts_with($realPath, $assetDir) && is_file($realPath)) {
$swooleRes->sendfile($realPath);
logRequest($swooleReq, 200, microtime(true) - $startTime);
return;
}
}

    // Fix #1/#5: DB 代理已在 workerStart 中完整挂载，请求处理时无需重复绑定。
    // 重复调用 container->instance() 和 Model::setConnectionResolver() 在高并发下会引入不必要的全局状态竞争。

    try {
$psrRequest = buildPsr7Request($swooleReq);
} catch (\Throwable $e) {
$swooleRes->status(400);
$swooleRes->end('Bad Request: ' . $e->getMessage());
return;
}

try {
$isDebug = defined('LOG_LEVEL') && LOG_LEVEL === 'debug';

if ($isDebug) {
    // ---- Profiling: 通过 Flarum 序列化器容器获取 DB 连接 ----
    $_dbResolver = \Flarum\Api\Serializer\AbstractSerializer::getContainer()->make('db');
    $_db = $_dbResolver->connection();
    $_db->enableQueryLog();
    $_handleStart = microtime(true);
}

try {
    if (empty($GLOBALS['flarum_handler'])) {
        $swooleRes->status(503);
        $swooleRes->header('Retry-After', '2');
        $swooleRes->end('Service Unavailable: worker initializing');
        return;
    }
    $psrResponse = $GLOBALS['flarum_handler']->handle($psrRequest);
} finally {
    if ($isDebug) {
        // 无论是否异常都清理 query log，防止连接回池时带着开启的日志导致内存增长
        $_handleTime = (microtime(true) - $_handleStart) * 1000;
        $_queries = $_db->getQueryLog();
        $_db->disableQueryLog();
        $_db->flushQueryLog();
    }
}

if ($isDebug) {
    // ---- Profiling: 输出查询统计 ----
    $_queryCount = count($_queries);
    $_queryTotalMs = 0;
    foreach ($_queries as $_q) { $_queryTotalMs += $_q['time'] ?? 0; }

    if ($_handleTime > 30) {
        $_phpTime = $_handleTime - $_queryTotalMs;
        error_log(sprintf('[Profile] %s %s → handle=%dms sql=%dms(×%d) php=%dms',
            $swooleReq->server['request_method'] ?? 'GET',
            $requestUri,
            (int)$_handleTime,
            (int)$_queryTotalMs,
            $_queryCount,
            (int)$_phpTime
        ));
        // 输出最慢的 3 条 SQL
        if ($_queryCount > 0) {
            usort($_queries, fn($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
            for ($_i = 0; $_i < min(3, $_queryCount); $_i++) {
                $_sq = $_queries[$_i];
                error_log(sprintf('  [Slow#%d] %.1fms: %s', $_i+1, $_sq['time'] ?? 0, substr($_sq['query'] ?? '', 0, 200)));
            }
        }
    }
}
} catch (\Throwable $e) {
error_log('[Flarum-Swoole-Co] 未捕获异常: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
$swooleRes->status(500);
$swooleRes->end('Internal Server Error');
logRequest($swooleReq, 500, microtime(true) - $startTime);
return;
}

emitResponse($psrResponse, $swooleRes);
logRequest($swooleReq, $psrResponse->getStatusCode(), microtime(true) - $startTime);

if (LSCACHE_ENABLED && isset($ctx['lscache_redis'])) {
try {
$redis = $ctx['lscache_redis'];
$purgeHeader = $psrResponse->getHeaderLine('X-LiteSpeed-Purge');
if ($purgeHeader) {
$purges = array_map('trim', explode(',', $purgeHeader));
$keysToDelete = [];
foreach ($purges as $purgeObj) {
if ($purgeObj === '*') {
$cursor = 0;
do {
$result = $redis->executeRaw(['SCAN', $cursor, 'MATCH', 'lscache:page:*', 'COUNT', 1000]);
if (!is_array($result) || count($result) < 2) break;
$cursor = $result[0];
$keys = $result[1];
if (!empty($keys)) {
$keysToDelete = array_merge($keysToDelete, $keys);
}
} while ($cursor > 0);
} elseif (str_starts_with($purgeObj, 'tag=')) {
$tag = substr($purgeObj, 4);
$pageKeys = $redis->smembers("lscache:tag:{$tag}");
if (!empty($pageKeys)) {
$keysToDelete = array_merge($keysToDelete, $pageKeys);
$redis->del(["lscache:tag:{$tag}"]);
}
}
}
if (!empty($keysToDelete)) {
$redis->del(array_unique($keysToDelete));
}
}

$cacheControl = $psrResponse->getHeaderLine('X-LiteSpeed-Cache-Control');
if ($isGuest && in_array($method, ['GET', 'HEAD']) && str_contains($cacheControl, 'public')) {
$body = $psrResponse->getBody();
$body->rewind();
$ttl = LSCACHE_DEFAULT_TTL;
if (preg_match('/max-age=(\d+)/', $cacheControl, $m)) {
$ttl = (int)$m[1];
}
$storeData = [
'status' => $psrResponse->getStatusCode(),
'headers' => [],
'body' => $body->getContents()
];
foreach ($psrResponse->getHeaders() as $name => $values) {
$lName = strtolower($name);
if (!in_array($lName, ['set-cookie', 'x-litespeed-cache-control', 'x-litespeed-tag', 'x-litespeed-purge'])) {
$storeData['headers'][$name] = implode(', ', $values);
}
}
$cacheKey = buildLSCacheKey($swooleReq);
$redis->setex($cacheKey, $ttl, json_encode($storeData, JSON_UNESCAPED_UNICODE));

$tagHeader = $psrResponse->getHeaderLine('X-LiteSpeed-Tag');
if ($tagHeader) {
$tags = array_map('trim', explode(',', $tagHeader));
foreach ($tags as $tag) {
if ($tag !== '') {
$redis->sadd("lscache:tag:{$tag}", $cacheKey);
$redis->expire("lscache:tag:{$tag}", $ttl);
}
}
}
}
} catch (\Throwable $e) {}
}

if (memory_get_usage() > WORKER_MEMORY_WATERMARK) {
if ($server instanceof \Swoole\Server) {
\Swoole\Coroutine\System::sleep(0.1);
$server->stop($GLOBALS['flarum_worker_id']);
}
}
});

// ============================================================
// 工具函数
// ============================================================
function buildLSCacheKey(SwooleRequest $req): string
{
$host = $req->header['host'] ?? 'localhost';
$uri = $req->server['request_uri'] ?? '/';
$dropQs = ['fbclid', 'gclid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', '_ga'];
$queryParams = $req->get ?? [];
foreach ($dropQs as $drop) {
unset($queryParams[$drop]);
}
ksort($queryParams);
$qs = http_build_query($queryParams);
$locale = $req->cookie['locale'] ?? 'default';
$vary = $req->cookie['flarum_lscache_vary'] ?? 'none';
$remember = $req->cookie['flarum_remember'] ?? 'guest';
$signature = "host={$host}&uri={$uri}&qs={$qs}&locale={$locale}&vary={$vary}&remember={$remember}";
return 'lscache:page:' . md5($signature);
}

function buildPsr7Request(SwooleRequest $req): ServerRequest
{
$method = strtoupper($req->server['request_method'] ?? 'GET');
$uri = $req->server['request_uri'] ?? '/';
if (!empty($req->server['query_string'])) {
$uri .= '?' . $req->server['query_string'];
}
$headers = $req->header ?? [];
$host = $headers['host'] ?? 'localhost';
$scheme = $headers['x-forwarded-proto'] ?? 'http';
$psrUri = new Uri($scheme . '://' . $host . $uri);

$cookies = $req->cookie ?? [];
$queryParams = $req->get ?? [];

$rawBody = $req->rawContent() ?: '';
$stream = new Stream('php://temp', 'wb+');
if ($rawBody !== '') {
$stream->write($rawBody);
$stream->rewind();
}

$psrHeaders = [];
foreach ($headers as $name => $value) {
$psrHeaders[$name] = [$value];
}

$serverParams = array_change_key_case($req->server ?? [], CASE_UPPER);
$serverParams['HTTP_HOST'] = $host;
$serverParams['REMOTE_ADDR'] = $req->server['remote_addr'] ?? '127.0.0.1';
if (isset($headers['x-forwarded-for'])) {
$serverParams['REMOTE_ADDR'] = trim(explode(',', $headers['x-forwarded-for'])[0]);
}

$uploadedFiles = buildUploadedFiles($req->files ?? []);

return new ServerRequest(
$serverParams,
$uploadedFiles,
$psrUri,
$method,
$stream,
$psrHeaders,
$cookies,
$queryParams,
$req->post ?? null
);
}

function buildUploadedFiles(array $files): array
{
$result = [];
foreach ($files as $key => $value) {
if (isset($value['tmp_name']) && is_string($value['tmp_name'])) {
$result[$key] = new \Laminas\Diactoros\UploadedFile(
$value['tmp_name'],
$value['size'] ?? 0,
$value['error'] ?? UPLOAD_ERR_OK,
$value['name'] ?? null,
$value['type'] ?? null
);
} elseif (isset($value['tmp_name']) && is_array($value['tmp_name'])) {
$result[$key] = [];
foreach ($value['tmp_name'] as $idx => $tmpPath) {
$result[$key][$idx] = new \Laminas\Diactoros\UploadedFile(
$tmpPath,
$value['size'][$idx] ?? 0,
$value['error'][$idx] ?? UPLOAD_ERR_OK,
$value['name'][$idx] ?? null,
$value['type'][$idx] ?? null
);
}
} else {
$result[$key] = buildUploadedFiles($value);
}
}
return $result;
}

function emitResponse(PsrResponse $psrResponse, SwooleResponse $swooleRes): void
{
$swooleRes->status($psrResponse->getStatusCode());
foreach ($psrResponse->getHeaders() as $name => $values) {
$lowerName = strtolower($name);
if ($lowerName === 'set-cookie') {
foreach ($values as $cookie) {
$swooleRes->header('Set-Cookie', $cookie, false);
}
} else {
$swooleRes->header($name, implode(', ', $values));
}
}
$body = $psrResponse->getBody();
$body->rewind();
$swooleRes->end($body->getContents());
}

function logRequest(SwooleRequest $req, int $statusCode, float $durationSec): void
{
$workerId = $GLOBALS['flarum_worker_id'] ?? '?';
$method = $req->server['request_method'] ?? 'GET';
$uri = $req->server['request_uri'] ?? '/';
$qs = $req->server['query_string'] ?? '';
$fullUri = $qs ? "{$uri}?{$qs}" : $uri;
$ip = $req->header['x-forwarded-for'] ?? $req->server['remote_addr'] ?? '-';
$durationMs = number_format($durationSec * 1000, 2);
$time = date('d/M/Y:H:i:s O');
echo "[{$time}] [W#{$workerId}] {$ip} \"{$method} {$fullUri}\" {$statusCode} {$durationMs}ms\n";
}

$server->on('start', function ($server) use ($host) {
if (str_starts_with($host, '/') && file_exists($host)) {
chmod($host, 0777);
echo "Socket 权限已自动修改为 777\n";
}
});

echo "Flarum Swoole Worker 启动中... (Coroutine 模式)\n";
echo "监听地址: http://{$host}:{$port}\n";
echo "Worker 数量: {$workerCount}\n";
echo "---\n";

$server->start();

