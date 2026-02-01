<?php

namespace Laravel\Octane\Swoole\Handlers;

use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Stream;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\ChannelPoolLock;
use Laravel\Octane\Swoole\Coroutine\CoordinatorManager;
use Laravel\Octane\Swoole\Coroutine\FacadeCache;
use Laravel\Octane\Swoole\Coroutine\WorkerPool;
use Laravel\Octane\Swoole\SwooleClient;
use Laravel\Octane\Swoole\SwooleExtension;
use Laravel\Octane\Swoole\WorkerState;
use Laravel\Octane\Worker;
use Swoole\Coroutine\Channel;
use Swoole\Http\Server;
use Throwable;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

class OnWorkerStart
{
    public function __construct(
        protected SwooleExtension $extension,
        protected $basePath,
        protected array $serverState,
        protected WorkerState $workerState,
        protected bool $shouldSetProcessName = true
    ) {
    }

    /**
     * Handle the "workerstart" Swoole event.
     *
     * @param  \Swoole\Http\Server  $server
     * @return void
     */
    public function __invoke($server, int $workerId)
    {
        // Log detailed worker start info with actual Swoole settings
        $maxRequest = $server->setting['max_request'] ?? 'not set';
        $reloadAsync = isset($server->setting['reload_async']) && $server->setting['reload_async'] ? 'true' : 'false';
        $workerNum = $server->setting['worker_num'] ?? 'unknown';
        $isTaskWorker = $workerId >= ($workerNum);
        
        $workerType = $isTaskWorker ? 'TASK WORKER' : 'WORKER';
        error_log("🚀 {$workerType} #{$workerId} STARTING (max_request: {$maxRequest}, reload_async: {$reloadAsync})");
        
        // Enable coroutine hooks to make blocking functions (sleep, file_get_contents, etc.) coroutine-safe
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        
        if ($this->shouldClearOpcodeCache()) {
            $this->clearOpcodeCache();
        }

        $this->workerState->server = $server;
        $this->workerState->workerId = $workerId;
        $this->workerState->workerPid = posix_getpid();
        $this->workerState->worker = $this->bootWorker($server, $workerId);

        $this->dispatchServerTickTaskEverySecond($server);
        $this->streamRequestsToConsole($server);

        if ($this->shouldSetProcessName) {
            $isTaskWorker = $workerId >= $server->setting['worker_num'];

            $this->extension->setProcessName(
                $this->serverState['appName'],
                $isTaskWorker ? 'task worker process' : 'worker process',
            );
        }
        
        error_log("✅ {$workerType} #{$workerId} (PID: {$this->workerState->workerPid}) initialized and ready!");
    }

    /**
     * Boot the Octane worker and application.
     *
     * @param  \Swoole\Http\Server  $server
     * @return \Laravel\Octane\Worker|null
     */
    protected function bootWorker($server, $workerId)
    {
        try {
            $workerNum = $server->setting['worker_num'] ?? 1;
            $isTaskWorker = $workerId >= $workerNum;

            // Task workers only need a single worker instance, not a pool
            // They handle background tasks (octane-tick) and don't serve HTTP requests
            if ($isTaskWorker) {
                return $this->bootTaskWorker($server, $workerId);
            }

            // Regular HTTP workers get a pool for concurrent request handling
            return $this->bootHttpWorker($server, $workerId);
        } catch (Throwable $e) {
            Stream::shutdown($e);

            $server->shutdown();
        }
    }

    /**
     * Boot a task worker with a single Worker instance.
     *
     * @param  \Swoole\Http\Server  $server
     * @param  int  $workerId
     * @return \Laravel\Octane\Worker|null
     */
    protected function bootTaskWorker($server, int $workerId)
    {
        error_log("📋 Booting task worker #{$workerId} with single instance (no pool needed)");

        // Clear Facade resolved instances
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        Context::clear();
        FacadeCache::disable();

        $worker = new Worker(
            new ApplicationFactory($this->basePath),
            new SwooleClient
        );

        $worker->boot([
            'octane.cacheTable' => $this->workerState->cacheTable,
            Server::class => $server,
            WorkerState::class => $this->workerState,
        ]);

        $this->configureRedisForCoroutineWorker($worker, 0, $workerId);

        $this->workerState->worker = $worker;
        $this->workerState->client = $worker->getClient() ?? new SwooleClient;
        $this->workerState->ready = true;

        error_log("✅ Task worker #{$workerId} initialized successfully");

        return $worker;
    }

    /**
     * Boot an HTTP worker with a pool for concurrent request handling.
     *
     * @param  \Swoole\Http\Server  $server
     * @param  int  $workerId
     * @return \Laravel\Octane\Worker|null
     */
    protected function bootHttpWorker($server, int $workerId)
    {
        $poolConfig = $this->serverState['octaneConfig']['swoole']['pool'] ?? [];
        // Pool size determines concurrent requests per Swoole worker
        // Each pool member is a Worker with its own Laravel app (~50-100MB each)
        // Trade-off: higher = more concurrency but more memory
        $poolSize = (int) ($poolConfig['size'] ?? 10);
        $minSize = (int) ($poolConfig['min_size'] ?? 1);
        $maxSize = (int) ($poolConfig['max_size'] ?? 100);
        $idleTimeout = (int) ($poolConfig['idle_timeout'] ?? 10);

        if ($minSize < 0) {
            $minSize = 0;
        }

        if ($maxSize < $minSize) {
            $maxSize = $minSize;
        }

        if ($maxSize < 1) {
            $maxSize = 1;
        }

        $poolSize = max($minSize, min($maxSize, $poolSize));

        error_log("🏊 Creating worker pool with size: {$poolSize} (min: {$minSize}, max: {$maxSize})");

        $channel = new Channel($maxSize);
        $poolLock = new ChannelPoolLock(new Channel(1));
        $workerPool = new WorkerPool(
            $channel,
            $minSize,
            $maxSize,
            fn (int $poolIndex) => $this->createPoolWorker($server, $workerId, $poolIndex),
            $poolLock,
            $idleTimeout
        );
        $workerPool->seed($poolSize);

        $this->workerState->clientPool = $channel;
        $this->workerState->workerPool = $workerPool;

        // Keep the first worker as the default for backward compatibility
        $this->workerState->worker = $this->workerState->clientPool->pop();
        $this->workerState->clientPool->push($this->workerState->worker);
        $this->workerState->client = $this->workerState->worker->getClient() ?? new SwooleClient;

        // Install CoroutineApplication proxy as the global container instance
        // This ensures ALL container resolution goes through our coroutine-aware proxy
        $baseApp = $this->workerState->worker->application();
        $coroutineApp = new CoroutineApplication($baseApp);
        Container::setInstance($coroutineApp);

        // Facades must use the coroutine-aware proxy to avoid state leaks between concurrent requests.
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($coroutineApp);
        FacadeCache::disable();

        // Store worker pool in context for easy access
        Context::set('octane.worker_pool', $this->workerState->clientPool);
        Context::set('octane.worker_id', $workerId);
        Context::set('octane.worker_pid', $this->workerState->workerPid);
        Context::set('octane.pool_size', $poolSize);

        error_log("✅ Worker pool created successfully with {$poolSize} instances");

        $this->warnIfDatabasePoolMinExceedsMaxConnections($this->workerState->worker, $server);

        // Signal that worker initialization is complete
        CoordinatorManager::until(CoordinatorManager::WORKER_START)->resume();

        $this->workerState->ready = true;

        return $this->workerState->worker;
    }

    /**
     * Create a new pooled Worker instance for coroutine handling.
     *
     * @param  \Swoole\Http\Server  $server
     * @param  int  $workerId
     * @param  int  $poolIndex
     * @return \Laravel\Octane\Worker
     */
    public function createPoolWorker(Server $server, int $workerId, int $poolIndex): Worker
    {
        // Do not clear Facade instances or Context here — this method is called during
        // dynamic pool growth while requests may be running, and clearing global state
        // would break those in-flight requests.

        $worker = new Worker(
            new ApplicationFactory($this->basePath),
            new SwooleClient
        );

        $worker->boot([
            'octane.cacheTable' => $this->workerState->cacheTable,
            Server::class => $server,
            WorkerState::class => $this->workerState,
        ]);

        $this->configureRedisForCoroutineWorker($worker, $poolIndex, $workerId);

        return $worker;
    }

    /**
     * Ensure Redis connections are safe for concurrent coroutines.
     *
     * Persistent phpredis connections are shared across instances in the same
     * process when the persistent_id is identical. In coroutine mode this can
     * cause cross-request contention or hangs. We assign a unique persistent_id
     * per pool worker to prevent shared sockets.
     *
     * @param  \Laravel\Octane\Worker  $worker
     * @param  int  $poolIndex
     * @param  int  $workerId
     * @return void
     */
    protected function configureRedisForCoroutineWorker(Worker $worker, int $poolIndex, int $workerId): void
    {
        $app = $worker->application();

        if (! $app->bound('config')) {
            return;
        }

        $config = $app->make('config');
        $redisConfig = $config->get('database.redis');

        if (! is_array($redisConfig)) {
            return;
        }

        $connectionNames = array_filter(array_keys($redisConfig), function ($name) {
            return ! in_array($name, ['client', 'options', 'clusters'], true);
        });

        $suffix = 'octane_worker_'.$workerId.'_pool_'.$poolIndex.'_'.getmypid();
        $updated = false;

        foreach ($connectionNames as $name) {
            if (! is_array($redisConfig[$name])) {
                continue;
            }

            if (($redisConfig[$name]['persistent'] ?? false) === true) {
                $baseId = $redisConfig[$name]['persistent_id'] ?? "octane_redis_{$name}";
                $redisConfig[$name]['persistent_id'] = $baseId.'_'.$suffix;
                $updated = true;
            }
        }

        if (($config->get('session.driver') ?? null) === 'redis' &&
            empty($config->get('session.connection')) &&
            isset($redisConfig['session'])) {
            $config->set('session.connection', 'session');
        }

        if ($updated) {
            $config->set('database.redis', $redisConfig);

            if ($app->bound('redis')) {
                $redis = $app->make('redis');
                foreach ($connectionNames as $name) {
                    $redis->purge($name);
                }
            }
        }
    }

    protected function warnIfDatabasePoolMinExceedsMaxConnections(Worker $worker, Server $server): void
    {
        $app = $worker->application();

        if (! $app->bound('config')) {
            return;
        }

        $config = $app->make('config');
        $connections = $config->get('database.connections', []);

        if (! is_array($connections) || empty($connections)) {
            return;
        }

        $workerNum = (int) ($server->setting['worker_num'] ?? 1);
        if ($workerNum < 1) {
            $workerNum = 1;
        }

        $buffer = (int) ($config->get('octane.swoole.pool.db_max_connections_buffer', 10));
        if ($buffer < 0) {
            $buffer = 0;
        }

        $db = $app->make('db');

        foreach ($connections as $name => $connectionConfig) {
            if (! is_array($connectionConfig)) {
                continue;
            }

            $driver = $connectionConfig['driver'] ?? null;
            if (! in_array($driver, ['mysql', 'mariadb'], true)) {
                continue;
            }

            $poolConfig = $connectionConfig['pool'] ?? [];
            $minConnections = 1;
            if (is_array($poolConfig)) {
                $minConnections = (int) ($poolConfig['min_connections'] ?? 1);
            }

            if ($minConnections <= 0) {
                continue;
            }

            $maxConnections = $this->fetchMysqlMaxConnections($db, $name);

            if ($maxConnections === null) {
                continue;
            }

            $maxAllowed = $maxConnections - $buffer;

            if ($maxAllowed <= 0) {
                continue;
            }

            $totalMin = $minConnections * $workerNum;

            if ($totalMin > $maxAllowed) {
                error_log("Warning: DB pool min_connections ({$minConnections}) * worker_num ({$workerNum}) = {$totalMin} exceeds max_connections ({$maxConnections}) minus buffer ({$buffer}) for connection '{$name}'.");
            }
        }
    }

    protected function fetchMysqlMaxConnections($db, string $connectionName): ?int
    {
        try {
            $connection = $db->connection($connectionName);
            $result = $connection->selectOne('SELECT @@max_connections AS max_connections');
            $connection->disconnect();

            if (is_object($result) && isset($result->max_connections)) {
                return (int) $result->max_connections;
            }

            if (is_array($result) && isset($result['max_connections'])) {
                return (int) $result['max_connections'];
            }
        } catch (Throwable $e) {
            return null;
        }

        return null;
    }

    /**
     * Start the Octane server tick to dispatch the tick task every second.
     *
     * @param  \Swoole\Http\Server  $server
     * @return void
     */
    protected function dispatchServerTickTaskEverySecond($server)
    {
        // ...
    }

    /**
     * Register the request handled listener that will output request information per request.
     *
     * @param  \Swoole\Http\Server  $server
     * @return void
     */
    protected function streamRequestsToConsole($server)
    {
        if (! $this->workerState->worker) {
            return;
        }

        $this->workerState->worker->onRequestHandled(function ($request, $response, $sandbox) {
            if (! $sandbox->environment('local', 'testing')) {
                return;
            }

            // Get request start time from coroutine context to avoid metrics corruption across concurrent requests.
            $requestStartTime = null;
            $cid = \Swoole\Coroutine::getCid();
            if ($cid > 0) {
                $context = \Swoole\Coroutine::getContext($cid);
                $requestStartTime = $context['request_start_time'] ?? null;
            }

            // Fallback to workerState if context not available (shouldn't happen)
            if ($requestStartTime === null) {
                $requestStartTime = $this->workerState->lastRequestTime ?? microtime(true);
            }

            Stream::request(
                $request->getMethod(),
                $request->fullUrl(),
                $response->getStatusCode(),
                (microtime(true) - $requestStartTime) * 1000,
            );
        });
    }

    /**
     * Determine if the opcode cache should be cleared.
     *
     * @return bool
     */
    protected function shouldClearOpcodeCache()
    {
        return $this->serverState['octaneConfig']['swoole']['clear_opcache'] ?? true;
    }

    /**
     * Clear the APCu and Opcache caches.
     *
     * @return void
     */
    protected function clearOpcodeCache()
    {
        foreach (['apcu_clear_cache', 'opcache_reset'] as $function) {
            if (function_exists($function)) {
                $function();
            }
        }
    }
}
