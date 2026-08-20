<?php

namespace Laravel\Octane\Swoole\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager as BaseDatabaseManager;
use Laravel\Octane\CurrentApplication;
use Laravel\Octane\Swoole\Coroutine\Context;

class DatabaseManager extends BaseDatabaseManager
{
    protected $pools = [];

    public function connection($name = null)
    {
        $this->syncApplication();
        $name = $name ?: $this->getDefaultConnection();

        // If we are NOT in a coroutine, fallback to parent behavior (standard singleton connection)
        if (!Context::inCoroutine()) {
            return parent::connection($name);
        }

        if (! $this->shouldPoolConnection($name)) {
            return parent::connection($name);
        }

        // Check if we already have a connection for this coroutine
        $contextKey = "db.connection.{$name}";
        $connection = Context::get($contextKey);

        if ($connection) {
            return $connection;
        }

        // Get from pool - returns a Laravel Connection directly
        $pool = $this->getPool($name);
        $connection = $pool->get();

        // Store connection in context
        Context::set($contextKey, $connection);
        Context::set("{$contextKey}.pool", $pool);

        $this->armCoroutineExitRelease();

        return $connection;
    }

    /**
     * Release whatever this coroutine still holds when it ends.
     *
     * Worker::handle() releases the HTTP request coroutine's connections
     * itself (with garbage-collected-first ordering), so this defer is a
     * no-op there: the context is already empty when it fires. It exists for
     * every OTHER borrower — child coroutines spawned by app code and
     * coroutine-based daemons — whose borrows the Worker never sees. Without
     * it those connections bypass release(), the pool's counter drifts up
     * one slot per borrow until "Connection pool exhausted", and a long-lived
     * borrower accumulates leaked server-side prepared statements that
     * max_lifetime recycling can never reach.
     *
     * The defer collects the coroutine's cycle garbage before releasing:
     * locals are already destroyed when defers run, so any PDOStatement the
     * coroutine still references lives in a cycle, and collecting now closes
     * it against a connection that is still idle and unborrowable — the same
     * safe-point ordering the Worker uses. Garbage that only becomes
     * collectable later stays bounded by the pool's max_lifetime.
     *
     * If Context::clear() runs mid-coroutine (the Worker does this once per
     * request), the armed flag is lost and a later borrow arms a second
     * defer. Both run at exit; the second walk finds an empty context and is
     * a cheap no-op, so the stacking is bounded and harmless for the request
     * path. Daemon loops that clear context repeatedly should release
     * explicitly via releaseConnections() instead of relying on this hook.
     */
    protected function armCoroutineExitRelease(): void
    {
        if (Context::get('db.exit_release_armed')) {
            return;
        }

        Context::set('db.exit_release_armed', true);

        \Swoole\Coroutine::defer(function (): void {
            try {
                if (gc_status()['roots'] > 0) {
                    gc_collect_cycles();
                }

                $this->releaseConnections();
            } catch (\Throwable $e) {
                error_log('⚠️ Failed to release DB connections at coroutine exit: '.$e->getMessage());
            }
        });
    }

    protected function getPool($name)
    {
        $this->syncApplication();
        if (!isset($this->pools[$name])) {
            $config = $this->configuration($name);
            
            // Pool configuration
            $poolConfig = $config['pool'] ?? [
                'min_connections' => 1,
                'max_connections' => 10,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'max_idle_time' => 60.0,
                'max_lifetime' => DatabasePool::DEFAULT_MAX_LIFETIME,
                'ping_after_idle' => 30.0,
            ];

            $this->pools[$name] = new DatabasePool(
                $poolConfig,
                $config,
                $name,
                $this->factory,
                fn (Connection $connection) => $this->configurePooledConnection($connection)
            );
        }
        return $this->pools[$name];
    }

    /**
     * Attach the current request's container services to a pooled connection.
     *
     * Illuminate\Database\DatabaseManager::configure() does this for ordinary
     * connections. Pooled connections are built straight from the factory, so
     * they arrive with no event dispatcher and no transactions manager. That
     * makes DB::listen() silent and afterCommit() throw.
     *
     * This runs on every checkout, not once at creation: a pooled connection
     * outlives the coroutine that first borrowed it, and 'db.transactions' is
     * scoped per coroutine by RequestScope.
     */
    protected function configurePooledConnection(Connection $connection): void
    {
        $app = CurrentApplication::get() ?: $this->app;

        if (! $app) {
            return;
        }

        if ($app->bound('events')) {
            $connection->setEventDispatcher($app['events']);
        }

        // Resolve through the same container the queue and event dispatchers
        // use, so a job dispatched with afterCommit() and the connection agree
        // on whether a transaction is open.
        if ($app->bound('db.transactions')) {
            $connection->setTransactionManager($app['db.transactions']);
        }
    }

    protected function shouldPoolConnection(string $name): bool
    {
        $driver = $this->configuration($name)['driver'] ?? null;

        return in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlite', 'sqlsrv'], true);
    }

    protected function syncApplication(): void
    {
        $app = CurrentApplication::get();

        if ($app && $app !== $this->app) {
            $this->setApplication($app);
        }
    }

    /**
     * Release this coroutine's pooled connections immediately.
     *
     * The Worker no longer uses this: it detaches connections, destroys the
     * request's object graph, and only then releases, so statements held in
     * cycle garbage cannot be destroyed while the connection is busy in
     * another coroutine (which leaks the server-side prepared statement).
     * Calling this mid-request re-pools connections without that ordering —
     * only use it when no statement from this coroutine can still be alive.
     */
    public function releaseConnections()
    {
        if (!Context::inCoroutine()) {
            return;
        }

        $released = 0;

        // Get all context keys and release connections
        $allContext = Context::all();
        foreach ($allContext as $key => $value) {
            if (str_ends_with($key, '.pool')) {
                // Get the connection
                $connectionKey = substr($key, 0, -strlen('.pool'));
                $connection = Context::get($connectionKey);

                if ($connection && $value instanceof DatabasePool) {
                    // Release connection back to pool
                    $value->release($connection);
                    $released++;
                }

                // Clean up context
                Context::delete($key);
                Context::delete($connectionKey);
            }
        }

        // Pruning drains and refills every pool's channel; skip it when this
        // walk found nothing - the request path already released and pruned
        // through the Worker, and its exit defer would otherwise pay a full
        // no-op prune on every request.
        if ($released > 0) {
            $this->pruneIdleConnections();
        }
    }

    public function pruneIdleConnections(): int
    {
        $closed = 0;

        foreach ($this->pools as $pool) {
            if ($pool instanceof DatabasePool) {
                $closed += $pool->pruneIdleConnections();
            }
        }

        return $closed;
    }

    public function poolStats(): array
    {
        return array_map(
            static fn (DatabasePool $pool): array => $pool->getStats(),
            $this->pools
        );
    }
}
