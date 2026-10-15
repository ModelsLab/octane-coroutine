<?php

namespace Laravel\Octane\Swoole\Redis;

use Closure;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager as BaseRedisManager;
use Laravel\Octane\Swoole\Coroutine\Context;
use Throwable;

/**
 * A Redis manager that hands every coroutine its own connection.
 *
 * Swoole hooks phpredis onto coroutine sockets. A socket is bound to the
 * coroutine that first reads from it, so if a second coroutine issues a command
 * on the same connection while the first is parked on a reply, Swoole raises
 * "Socket#N has already been bound to another coroutine#M". That error is
 * raised by the scheduler rather than thrown through the calling frame, so
 * userland try/catch cannot contain it and the whole worker dies.
 *
 * RequestScope already gives each coroutine its own manager, but that only
 * covers code that resolves `redis` during the request. Objects captured at
 * boot keep pointing at the worker-level manager -- the framework's
 * RateLimiter singleton is one, since it holds a Repository -> RedisStore that
 * calls back into whichever manager it was built with. Making the worker-level
 * manager coroutine-aware closes that hole for every such holder, because
 * RedisStore re-resolves the connection on each operation.
 *
 * Connections are released by the worker when the request coroutine finishes. With
 * `octane.swoole.redis_pool.enabled`, clean phpredis connections go back to a per-worker
 * pool (see RedisConnectionPool) instead of being closed.
 */
class CoroutineRedisManager extends BaseRedisManager
{
    /**
     * Context key prefix for per-coroutine connections.
     *
     * @var string
     */
    public const CONTEXT_PREFIX = 'octane.redis.connection.';

    /**
     * Context key holding the connection names opened by this coroutine.
     *
     * @var string
     */
    public const CONTEXT_NAMES = 'octane.redis.connection_names';

    /**
     * A sibling manager configured without persistent connections.
     *
     * Persistent phpredis sockets are shared process-wide by persistent_id, so
     * resolving through the original configuration would hand two coroutines
     * the same socket again.
     *
     * @var \Illuminate\Redis\RedisManager|null
     */
    protected $coroutineResolver = null;

    /**
     * The worker's Redis connection pool, shared by every manager and clone in the process.
     *
     * @var \Laravel\Octane\Swoole\Redis\RedisConnectionPool|null
     */
    protected static ?RedisConnectionPool $pool = null;

    /**
     * Whether the pool configuration has been read in this process.
     *
     * @var bool
     */
    protected static bool $poolConfigured = false;

    /**
     * Get a Redis connection by name.
     *
     * @param  \UnitEnum|string|null  $name
     * @return \Illuminate\Redis\Connections\Connection
     */
    public function connection($name = null)
    {
        if (! Context::inCoroutine()) {
            return parent::connection($name);
        }

        $name = $this->normalizeConnectionName($name);

        $key = self::CONTEXT_PREFIX.$name;

        if (($connection = Context::get($key)) instanceof Connection) {
            return $connection;
        }

        $poolKey = $this->pool() !== null ? $this->poolKey($name) : null;
        $pool = $poolKey !== null ? $this->pool() : null;

        $connection = $pool?->borrow($poolKey);

        if ($connection === null) {
            $connection = $this->coroutineResolver()->resolve($name);

            if ($pool !== null && $connection instanceof PooledPhpRedisConnection) {
                $pool->track($connection);
            }
        }

        $connection = $this->configure($connection, $name);

        Context::set($key, $connection);

        $names = Context::get(self::CONTEXT_NAMES, []);
        $names[$name] = $poolKey ?? true;
        Context::set(self::CONTEXT_NAMES, $names);

        return $connection;
    }

    /**
     * Close (or return to the pool) and forget the connections opened by the current coroutine.
     *
     * @return int
     */
    public function releaseConnections(): int
    {
        return $this->releaseDetachedConnections($this->detachConnections());
    }

    /**
     * Take the current coroutine's connections out of its context without closing them.
     *
     * The worker detaches them before the coroutine context is cleared and releases them
     * after the request has been torn down, so nothing from the request can still use a
     * socket that another request has borrowed from the pool.
     *
     * @return list<array{0: \Illuminate\Redis\Connections\Connection, 1: string|true}>
     */
    public function detachConnections(): array
    {
        if (! Context::inCoroutine()) {
            return [];
        }

        $detached = [];

        foreach (Context::get(self::CONTEXT_NAMES, []) as $name => $poolKey) {
            $key = self::CONTEXT_PREFIX.$name;
            $connection = Context::get($key);

            if ($connection instanceof Connection) {
                $detached[] = [$connection, $poolKey];
            }

            Context::delete($key);
        }

        Context::delete(self::CONTEXT_NAMES);

        return $detached;
    }

    /**
     * Return detached connections to the pool, closing any the pool will not take.
     *
     * @param  list<array{0: \Illuminate\Redis\Connections\Connection, 1: string|true}>  $detached
     * @return int
     */
    public function releaseDetachedConnections(array $detached): int
    {
        $pool = $this->pool();

        foreach ($detached as [$connection, $poolKey]) {
            $pooled = $pool !== null
                && is_string($poolKey)
                && $connection instanceof PooledPhpRedisConnection
                && $pool->release($poolKey, $connection);

            if (! $pooled) {
                $this->disconnectConnection($connection);
            }
        }

        return count($detached);
    }

    /**
     * Disconnect the given connection and remove it from the local cache.
     *
     * @param  string|null  $name
     * @return void
     */
    public function purge($name = null)
    {
        if (Context::inCoroutine()) {
            $name = $this->normalizeConnectionName($name);
            $key = self::CONTEXT_PREFIX.$name;

            if (($connection = Context::get($key)) instanceof Connection) {
                $this->disconnectConnection($connection);
            }

            Context::delete($key);

            $names = Context::get(self::CONTEXT_NAMES, []);
            unset($names[$name]);
            Context::set(self::CONTEXT_NAMES, $names);

            return;
        }

        parent::purge($name);
    }

    /**
     * Register a custom driver creator Closure.
     *
     * @param  string  $driver
     * @param  \Closure  $callback
     * @return $this
     */
    public function extend($driver, Closure $callback)
    {
        $this->coroutineResolver = null;

        return parent::extend($driver, $callback);
    }

    /**
     * Set the default driver.
     *
     * @param  string  $driver
     * @return void
     */
    public function setDriver($driver)
    {
        $this->coroutineResolver = null;

        parent::setDriver($driver);
    }

    /**
     * Resolve the sibling manager used to open coroutine-local connections.
     *
     * A separate instance is used rather than temporarily rewriting $this->config
     * because resolving performs I/O: the coroutine can park mid-connect and let
     * another coroutine observe (or restore) the swapped configuration.
     *
     * @return \Illuminate\Redis\RedisManager
     */
    protected function coroutineResolver(): BaseRedisManager
    {
        if ($this->coroutineResolver === null) {
            $resolver = new CoroutineRedisResolver(
                $this->app, $this->driver, $this->coroutineSafeConfig()
            );

            $resolver->pooled = $this->pool() !== null;

            foreach ($this->customCreators as $driver => $creator) {
                $resolver->extend($driver, $creator);
            }

            $this->coroutineResolver = $resolver;
        }

        return $this->coroutineResolver;
    }

    /**
     * Get the worker's Redis pool, or null when pooling is disabled.
     *
     * @return \Laravel\Octane\Swoole\Redis\RedisConnectionPool|null
     */
    protected function pool(): ?RedisConnectionPool
    {
        if (! static::$poolConfigured) {
            $config = [];

            try {
                $config = (array) $this->app->make('config')->get('octane.swoole.redis_pool', []);
            } catch (Throwable) {
                // No configuration: pooling stays off.
            }

            static::$pool = filter_var($config['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)
                ? RedisConnectionPool::fromConfig($config)
                : null;

            static::$poolConfigured = true;
        }

        return static::$pool;
    }

    /**
     * Forget the worker's Redis pool, closing its idle connections.
     *
     * @return void
     */
    public static function flushPool(): void
    {
        static::$pool?->flush();
        static::$pool = null;
        static::$poolConfigured = false;
    }

    /**
     * Identify the connection settings a pooled connection was opened with.
     *
     * @param  string  $name
     * @return string
     */
    protected function poolKey(string $name): ?string
    {
        try {
            return $name.'|'.md5(serialize([
                $this->driver,
                $this->config[$name] ?? null,
                $this->config['options'] ?? null,
            ]));
        } catch (Throwable) {
            // A configuration that cannot be serialized (e.g. holding a closure) is not pooled.
            return null;
        }
    }

    /**
     * Build a configuration with persistent connections disabled.
     *
     * @return array
     */
    protected function coroutineSafeConfig(): array
    {
        $config = $this->config;

        foreach ($config as $name => $connection) {
            if (! is_array($connection) || in_array($name, ['options', 'clusters'], true)) {
                continue;
            }

            $config[$name]['persistent'] = false;
            unset($config[$name]['persistent_id']);
        }

        return $config;
    }

    /**
     * Normalize a connection name to a string key.
     *
     * @param  mixed  $name
     * @return string
     */
    protected function normalizeConnectionName($name): string
    {
        if ($name instanceof \BackedEnum) {
            $name = $name->value;
        } elseif ($name instanceof \UnitEnum) {
            $name = $name->name;
        }

        return (string) ($name ?: 'default');
    }

    /**
     * Close a connection, ignoring transport level failures.
     *
     * @param  \Illuminate\Redis\Connections\Connection  $connection
     * @return void
     */
    protected function disconnectConnection(Connection $connection): void
    {
        try {
            if (method_exists($connection, 'disconnect')) {
                $connection->disconnect();
            }
        } catch (Throwable $e) {
            // The socket is being discarded either way.
        }
    }
}
