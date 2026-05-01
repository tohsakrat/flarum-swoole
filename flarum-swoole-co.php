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
const POOL_SIZE = 20;

/** LSCache 功能开关（true = 启用游客缓存加速，false = 禁用） */
const LSCACHE_ENABLED = true;

/** 默认缓存 TTL（秒），Flarum 的 X-LiteSpeed-Cache-Control max-age 优先 */
const LSCACHE_DEFAULT_TTL = 604800; // 7 天

/** 协程并发序列化开关（true = 启用 WaitGroup 并发，对 Admin 面板效果显著） */
const COROUTINE_SERIALIZER_ENABLED = true;

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
                    try   { $results[$idx] = $resource->toArray(); }
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
$shared = $ctx['view_shared'] ?? [];
return $this->realView->file($path, array_merge($shared, $data), $mergeData);
}

public function make($view, $data = [], $mergeData = []) {
$ctx = \Swoole\Coroutine::getContext();
$shared = $ctx['view_shared'] ?? [];
return $this->realView->make($view, array_merge($shared, $data), $mergeData);
}

public function share($key, $value = null) {
$ctx = \Swoole\Coroutine::getContext();
if (!isset($ctx['view_shared'])) {
$ctx['view_shared'] = [];
}
if (is_array($key)) {
$ctx['view_shared'] = array_merge($ctx['view_shared'], $key);
} else {
$ctx['view_shared'][$key] = $value;
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

public function __construct($original, int $size = 20) {
$this->channel = new \Swoole\Coroutine\Channel($size);
for ($i = 0; $i < $size; $i++) {
$clone = clone $original;
if (property_exists($clone, 'connections')) {
$ref = new \ReflectionClass($clone);
$prop = $ref->getProperty('connections');
$prop->setAccessible(true);
$prop->setValue($clone, []);
}
$this->channel->push($clone);
}
}

protected function getManager() {
$ctx = \Swoole\Coroutine::getContext();
if (!isset($ctx['db_manager'])) {
$ctx['db_manager'] = $this->channel->pop();
\Swoole\Coroutine\defer(function() use ($ctx) {
if (isset($ctx['db_manager'])) {
$this->channel->push($ctx['db_manager']);
}
});
}
return $ctx['db_manager'];
}

public function connection($name = null) { return $this->getManager()->connection($name); }
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
foreach ($connections as $connName => $connConfig) {
if (is_array($connConfig)) {
$this->rawConfigs[$connName] = $connConfig;
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
} catch (\Throwable $e) {
fwrite(STDERR, "[Worker #{$workerId}] Flarum 启动失败: {$e->getMessage()}\n");
fwrite(STDERR, $e->getTraceAsString() . "\n");
$server->stop($workerId);
return;
}

$container = $app->getContainer();
$GLOBALS['flarum_container'] = $container;
$GLOBALS['flarum_worker_id'] = $workerId;

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
$originalDb = $container->make('db');
$dbProxy = new CoroutineDbProxy($originalDb, 20);
$container->instance('db', $dbProxy);
\Illuminate\Database\Eloquent\Model::setConnectionResolver($dbProxy);
echo "[Worker #{$workerId}] DB 引擎协程池代理挂载成功。\n";
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
// DB 保活定时器
// ----------------------------------------------------------
\Swoole\Timer::tick((int)(DB_HEARTBEAT_HOURS * 3600 * 1000), function () use ($workerId) {
try {
$db = $GLOBALS['flarum_container']->make('db');
$db->select('SELECT 1');
} catch (\Throwable $e) {
try {
$GLOBALS['flarum_container']->make('db')->reconnect();
} catch (\Throwable $reconnectErr) {}
}
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

if (isset($GLOBALS['swoole_redis_pool'])) {
$ctx['lscache_redis'] = $GLOBALS['swoole_redis_pool']->pop();
\Swoole\Coroutine\defer(function() use ($ctx) {
$GLOBALS['swoole_redis_pool']->push($ctx['lscache_redis']);
});
}

if (isset($GLOBALS['swoole_session_redis_pool'])) {
$ctx['lscache_session_redis'] = $GLOBALS['swoole_session_redis_pool']->pop();
\Swoole\Coroutine\defer(function() use ($ctx) {
$GLOBALS['swoole_session_redis_pool']->push($ctx['lscache_session_redis']);
});
}

$method = strtoupper($swooleReq->server['request_method'] ?? 'GET');

$isGuest = true;
if (isset($swooleReq->header['authorization'])) {
$isGuest = false;
} elseif (!empty($swooleReq->cookie['flarum_remember'])) {
$isGuest = false;
} elseif (!empty($swooleReq->cookie['flarum_session']) && isset($ctx['lscache_session_redis'])) {
$sessionId = $swooleReq->cookie['flarum_session'];

$redis = $ctx['lscache_session_redis'];

$sessionData = $redis->get($sessionId)
?: $redis->get('flarum_cache:' . $sessionId)
?: $redis->get('flarum_session:' . $sessionId);

if ($sessionData && !str_starts_with($sessionData, 's:118:"a:2:{s:6:"_token"')) {
$isGuest = false;
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


try {
$psrRequest = buildPsr7Request($swooleReq);
} catch (\Throwable $e) {
$swooleRes->status(400);
$swooleRes->end('Bad Request: ' . $e->getMessage());
return;
}

try {
$psrResponse = $GLOBALS['flarum_handler']->handle($psrRequest);
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
