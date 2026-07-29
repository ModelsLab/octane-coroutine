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
 * Connections are released by the worker when the request coroutine finishes.
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

        $connection = $this->configure(
            $this->coroutineResolver()->resolve($name), $name
        );

        Context::set($key, $connection);

        $names = Context::get(self::CONTEXT_NAMES, []);
        $names[$name] = true;
        Context::set(self::CONTEXT_NAMES, $names);

        return $connection;
    }

    /**
     * Close and forget the connections opened by the current coroutine.
     *
     * @return int
     */
    public function releaseConnections(): int
    {
        if (! Context::inCoroutine()) {
            return 0;
        }

        $released = 0;

        foreach (array_keys(Context::get(self::CONTEXT_NAMES, [])) as $name) {
            $key = self::CONTEXT_PREFIX.$name;
            $connection = Context::get($key);

            if ($connection instanceof Connection) {
                $this->disconnectConnection($connection);
                $released++;
            }

            Context::delete($key);
        }

        Context::delete(self::CONTEXT_NAMES);

        return $released;
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
            $resolver = new BaseRedisManager(
                $this->app, $this->driver, $this->coroutineSafeConfig()
            );

            foreach ($this->customCreators as $driver => $creator) {
                $resolver->extend($driver, $creator);
            }

            $this->coroutineResolver = $resolver;
        }

        return $this->coroutineResolver;
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
