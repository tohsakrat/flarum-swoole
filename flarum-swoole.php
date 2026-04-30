<?php
/**
 * Flarum Swoole Coroutine Worker
 *
 * 基于 Swoole 的 Flarum 常驻内存入口
 *
 * 依赖安装:
 * pecl install swoole
 * composer require laminas/laminas-diactoros  (Flarum 自带，无需额外安装)
 *
 * 启动:
 * php flarum-worker-swoole.php
 *
 * 停止:
 * kill $(cat /tmp/flarum-swoole.pid)
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
//$host        = '0.0.0.0';
$host = '/tmp/flarum.sock';
//$port        = 2345;
$port        = 0;
// ---------------------------------------------------------------
// Worker 数量策略（针对弱单核 + 3~5个并发API请求/页面 的场景）
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
// SWOOLE_BASE 模式：无 Master→Worker IPC 开销，适合低延迟场景
// ---------------------------------------------------------------
//$server = new Server($host, $port, SWOOLE_BASE, SWOOLE_SOCK_TCP);
$server = new Server($host, 0,  SWOOLE_BASE, SWOOLE_SOCK_UNIX_STREAM);

$server->set([
    'worker_num'            => $workerCount,
    'task_worker_num'       => 0,
    'max_request'           => 0,
    'reload_async'          => true,
    'max_wait_time'         => 60,
    'enable_reuse_port'     => false,
    'http_compression'      => false,
    'enable_coroutine' => false, // 彻底关闭请求级的自动协程
    
    // TCP 层延迟优化（禁用 Nagle 算法，确保 JSON 响应低延迟）
    'open_tcp_nodelay'      => true,
    'open_tcp_keepalive'    => true,
    'tcp_keepidle'          => 60,
    'tcp_keepinterval'      => 10,
    'tcp_keepcount'         => 3,
    
    // 内存与缓冲区调参
    'buffer_output_size'    => 16 * 1024 * 1024,
    'socket_buffer_size'    => 512 * 1024,
    
    // 设置最大数据包为 50MB (单位: 字节)
    'package_max_length' => 50 * 1024 * 1024,
    
    // 协程调度参数
    'max_coroutine'         => 10000,
    'stack_size'            => 8 * 1024 * 1024,
    
    // 进程管理
    'daemonize'             => false,
    'pid_file'              => '/tmp/flarum-swoole.pid',
    'log_file'              => __DIR__ . '/storage/logs/swoole.log',
    'log_level'             => SWOOLE_LOG_WARNING,
]);

// ============================================================
// Worker 进程启动
// ============================================================
$server->on('workerStart', function (Server $server, int $workerId) {
    // 激进的内存上限，应对 Flarum 偶发重度编译
    ini_set('memory_limit', '1536M');

    // LSCache 兼容层：环境伪装
    $_SERVER['X-LSCACHE'] = 'on';
    $_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed Web Server (Swoole Emulator)';

    echo "[Worker #{$workerId}] 启动中，正在加载 Flarum 应用...\n";

    try {
        $site = require __DIR__ . '/site.php';
        $app  = $site->bootApp();
    } catch (\Throwable $e) {
        fwrite(STDERR, "[Worker #{$workerId}] Flarum 启动失败: {$e->getMessage()}\n");
        fwrite(STDERR, $e->getTraceAsString() . "\n");
        $server->stop($workerId);
        return;
    }

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
                                        // 提取可能存在的多数据库配置
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

        echo "[Worker #{$workerId}] LSCache 成功读取 Redis 配置: " . json_encode($redisSettings) . "\n";
        
        // 主 Redis 连接 (用于缓存)
        $swooleRedis = new \Predis\Client($redisSettings);
        $swooleRedis->connect();
        $GLOBALS['swoole_redis'] = $swooleRedis;
        
        // 独立 Session 连接
        if (isset($redisSettings['session_database'])) {
            $sessionSettings = $redisSettings;
            $sessionSettings['database'] = $redisSettings['session_database'];
            unset($sessionSettings['session_database']);
            $swooleSessionRedis = new \Predis\Client($sessionSettings);
            $swooleSessionRedis->connect();
            $GLOBALS['swoole_session_redis'] = $swooleSessionRedis;
            echo "[Worker #{$workerId}] 独立 Session 数据库(DB:{$sessionSettings['database']})连接建立。\n";
        } else {
            $GLOBALS['swoole_session_redis'] = $swooleRedis;
        }

    } catch (\Throwable $e) {
        echo "[Worker #{$workerId}] LSCache: Redis 连接失败或读取配置失败: {$e->getMessage()}\n";
    }

    // ----------------------------------------------------------
    // DB 保活定时器 (1小时心跳)
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
    // ----------------------------------------------------------
    $recycleInterval = (int)(0.5 * 3600 * 1000);  // 6 小时
    $recycleOffset   = $workerId * 180 * 1000;  // 错开 180 秒
    \Swoole\Timer::after($recycleOffset, function () use ($server, $workerId, $recycleInterval) {
        \Swoole\Timer::tick($recycleInterval, function () use ($server, $workerId) {
            echo "[Worker #{$workerId}] [" . date('H:i:s') . "] 定时清理碎片，Worker 退出中...\n";
            $server->stop($workerId);
        });
    });

    echo "[Worker #{$workerId}] Flarum 加载成功。\n";
});

// ============================================================
// 处理每个 HTTP 请求（注意使用 use($server) 注入当前实例）
// ============================================================
$server->on('request', function (SwooleRequest $swooleReq, SwooleResponse $swooleRes) use ($server) {
    $startTime = microtime(true);

    $method = strtoupper($swooleReq->server['request_method'] ?? 'GET');
    $uri = $swooleReq->server['request_uri'] ?? '/';
    $qs = $swooleReq->server['query_string'] ?? '';
    
    // ==========================================================
    // 缓存绕过验证：精确识别游客
    // ==========================================================
    $isGuest = true;
    if (isset($swooleReq->header['authorization'])) {
        $isGuest = false;
    } elseif (!empty($swooleReq->cookie['flarum_remember'])) {
        $isGuest = false;
    } elseif (!empty($swooleReq->cookie['flarum_session']) && isset($GLOBALS['swoole_session_redis'])) {
        $sessionId = $swooleReq->cookie['flarum_session'];
        $redis = $GLOBALS['swoole_session_redis'];
        
        $sessionData = $redis->get($sessionId) 
                    ?: $redis->get('flarum_cache:' . $sessionId) 
                    ?: $redis->get('flarum_session:' . $sessionId)
                    ?: $redis->get('flarum_cache_' . $sessionId)
                    ?: $redis->get('flarum_session_' . $sessionId);
        
        if ($sessionData && !str_starts_with($sessionData, 's:118:"a:2:{s:6:"_token"')) {
            $isGuest = false;
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
                    return; // 直接返回，绕过 Flarum
                }
            }
        } catch (\Throwable $e) {} // 忽略 Redis 报错，降级处理
    }

    // ----------------------------------------------------------
    // 静态文件零拷贝放行
    // ----------------------------------------------------------
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

    // ----------------------------------------------------------
    // 协程 Context 隔离
    // ----------------------------------------------------------
    $ctx = \Swoole\Coroutine::getContext();
    $ctx['request_id'] = uniqid('req_', true);
    $ctx['worker_id']  = $GLOBALS['flarum_worker_id'] ?? 0;

    // ----------------------------------------------------------
    // [沙箱] 清理上一个协程在视图引擎单例上残留的错误变量
    // ----------------------------------------------------------
    $container = $GLOBALS['flarum_container'];
    try {
        if ($container->bound('view')) {
            $view = $container->make('view');
            $refClass = new \ReflectionClass($view);
            if ($refClass->hasProperty('shared')) {
                $refProp = $refClass->getProperty('shared');
                $refProp->setAccessible(true);
                $shared = $refProp->getValue($view);
                if (is_array($shared) && array_key_exists('errors', $shared)) {
                    unset($shared['errors']);
                    $refProp->setValue($view, $shared);
                }
            }
        }
    } catch (\Throwable $ignored) {}

    // ----------------------------------------------------------
    // 请求转换与管道处理
    // ----------------------------------------------------------
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
        error_log('[Flarum-Swoole] 未捕获异常: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        $swooleRes->status(500);
        $swooleRes->end('Internal Server Error');
        logRequest($swooleReq, 500, microtime(true) - $startTime);
        return;
    }

    emitResponse($psrResponse, $swooleRes);
    logRequest($swooleReq, $psrResponse->getStatusCode(), microtime(true) - $startTime);

    // ----------------------------------------------------------
    // LSCache 兼容层：响应分析与缓存操作
    // ----------------------------------------------------------
    if (isset($GLOBALS['swoole_redis'])) {
        try {
            /** @var \Predis\Client $redis */
            $redis = $GLOBALS['swoole_redis'];
            
            // 1. 精准清除 (Purge) - 使用 SCAN 防止阻塞
            $purgeHeader = $psrResponse->getHeaderLine('X-LiteSpeed-Purge');
            if ($purgeHeader) {
                $purges = array_map('trim', explode(',', $purgeHeader));
                $keysToDelete = [];
                
                foreach ($purges as $purgeObj) {
                    if ($purgeObj === '*') {
                        // 使用 SCAN 迭代替代 KEYS，避免单线程阻塞
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

            // 2. 写入缓存
            $cacheControl = $psrResponse->getHeaderLine('X-LiteSpeed-Cache-Control');
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
        } catch (\Throwable $e) {
            echo "[LSCache] Redis 异常: " . $e->getMessage() . "\n";
        }
    }

    // ----------------------------------------------------------
    // [健康检查] 内存水位监控 
    // ----------------------------------------------------------
    if (memory_get_usage() > 256 * 1024 * 1024) {
        echo "[Worker #{$GLOBALS['flarum_worker_id']}] 内存超过 128MB，触发平滑重启...\n";
        if ($server instanceof \Swoole\Server) {
           // \Swoole\Coroutine\System::sleep(0.1);
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
    $uri  = $req->server['request_uri'] ?? '/';
    
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
    $method  = strtoupper($req->server['request_method'] ?? 'GET');
    $uri     = $req->server['request_uri'] ?? '/';
    if (!empty($req->server['query_string'])) {
        $uri .= '?' . $req->server['query_string'];
    }
    $headers = $req->header ?? [];
    $host    = $headers['host'] ?? 'localhost';
    $scheme  = $headers['x-forwarded-proto'] ?? 'http';
    $psrUri  = new Uri($scheme . '://' . $host . $uri);
    
    $cookies = $req->cookie ?? [];
    $queryParams = $req->get ?? [];
    
    $rawBody = $req->rawContent() ?: '';
    $stream  = new Stream('php://temp', 'wb+');
    if ($rawBody !== '') {
        $stream->write($rawBody);
        $stream->rewind();
    }
    
    $psrHeaders = [];
    foreach ($headers as $name => $value) {
        $psrHeaders[$name] = [$value];
    }
    
    $serverParams = array_change_key_case($req->server ?? [], CASE_UPPER);
    $serverParams['HTTP_HOST']    = $host;
    $serverParams['REMOTE_ADDR']  = $req->server['remote_addr'] ?? '127.0.0.1';
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

/**
 * 递归构建多维上传文件结构，完全符合 PSR-7 规范
 */
function buildUploadedFiles(array $files): array
{
    $result = [];
    foreach ($files as $key => $value) {
        if (isset($value['tmp_name']) && is_string($value['tmp_name'])) {
            $result[$key] = new \Laminas\Diactoros\UploadedFile(
                $value['tmp_name'],
                $value['size']   ?? 0,
                $value['error']  ?? UPLOAD_ERR_OK,
                $value['name']   ?? null,
                $value['type']   ?? null
            );
        } elseif (isset($value['tmp_name']) && is_array($value['tmp_name'])) {
            $result[$key] = [];
            foreach ($value['tmp_name'] as $idx => $tmpPath) {
                $result[$key][$idx] = new \Laminas\Diactoros\UploadedFile(
                    $tmpPath,
                    $value['size'][$idx]   ?? 0,
                    $value['error'][$idx]  ?? UPLOAD_ERR_OK,
                    $value['name'][$idx]   ?? null,
                    $value['type'][$idx]   ?? null
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
    $method   = $req->server['request_method'] ?? 'GET';
    $uri      = $req->server['request_uri']    ?? '/';
    $qs       = $req->server['query_string']   ?? '';
    $fullUri  = $qs ? "{$uri}?{$qs}" : $uri;
    $ip       = $req->header['x-forwarded-for'] ?? $req->server['remote_addr'] ?? '-';
    $durationMs = number_format($durationSec * 1000, 2);
    $time = date('d/M/Y:H:i:s O');
    echo "[{$time}] [W#{$workerId}] {$ip} \"{$method} {$fullUri}\" {$statusCode} {$durationMs}ms\n";
}




// ============================================================
// 主进程启动事件（修改 Socket 权限）
// ============================================================
$server->on('start', function ($server) use ($host) {
    // 确保是 Unix Socket 路径且文件存在时，将其权限强行改为 777
    if (str_starts_with($host, '/') && file_exists($host)) {
        chmod($host, 0777);
        echo "Socket 权限已自动修改为 777\n";
    }
});

// ============================================================
// 启动服务器
// ============================================================
echo "Flarum Swoole Worker 启动中...\n";
echo "监听地址: http://{$host}:{$port}\n";
echo "Worker 数量: {$workerCount}\n";
echo "---\n";



$server->start();
