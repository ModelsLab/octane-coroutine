<?php

namespace Laravel\Octane\Swoole\Database;

use Swoole\Coroutine\Channel;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Connection;
use Swoole\Timer;
use Throwable;

/**
 * Connection pool for Laravel using Swoole Channels for coroutine-safe pooling.
 */
class DatabasePool
{
    protected Channel $channel;
    protected int $currentConnections = 0;
    protected array $config;
    protected string $name;
    protected ConnectionFactory $factory;
    protected array $connectionConfig;
    protected array $idleSince = [];
    protected ?int $idlePruneTimerId = null;

    public function __construct(array $config, array $connectionConfig, string $name, ConnectionFactory $factory)
    {
        $this->config = $config;
        $this->name = $name;
        $this->factory = $factory;
        $this->connectionConfig = $connectionConfig;

        // Create a channel for pooling connections
        // Channel size = max_connections
        $maxConnections = $config['max_connections'] ?? 10;
        $this->channel = new Channel($maxConnections);

        // Pre-create minimum connections
        $minConnections = $config['min_connections'] ?? 1;
        for ($i = 0; $i < $minConnections; $i++) {
            try {
                $connection = $this->createConnection();
                $this->markIdle($connection);
                $this->channel->push($connection);
            } catch (Throwable $e) {
                error_log('❌ Failed to create initial pool connection: '.$e->getMessage());
            }
        }

        $this->startIdlePruner();
    }

    /**
     * Get a connection from the pool
     */
    public function get()
    {
        $this->pruneIdleConnections();

        $waitTimeout = $this->config['wait_timeout'] ?? 3.0;
        $maxConnections = $this->config['max_connections'] ?? 10;

        // Fast path: try a non-blocking pop first to avoid unnecessary waits.
        $connection = $this->channel->pop(0.001);

        if ($connection === false) {
            // If we can grow the pool, create immediately instead of waiting.
            if ($this->currentConnections < $maxConnections) {
                $connection = $this->createConnection();
            } else {
                // Pool is at max; wait for a connection to be released.
                $connection = $this->channel->pop($waitTimeout);

                if ($connection === false) {
                    throw new \RuntimeException('Connection pool exhausted. Cannot establish new connection before wait_timeout.');
                }
            }
        }

        $this->markBorrowed($connection);

        // Check if connection is still valid
        if (! $this->checkConnection($connection)) {
            $connection = $this->reconnect($connection);
        }

        // Defensive cleanup on checkout as well as release. If a previous
        // request left PDO or Laravel transaction state dirty, never hand that
        // connection to the next coroutine.
        try {
            $this->resetConnection($connection);
        } catch (Throwable $e) {
            error_log('❌ Dirty DB connection could not be reset on checkout: '.$e->getMessage());
            $this->closeConnection($connection);
            $this->currentConnections--;
            $connection = $this->createConnection();
        }

        return $connection;
    }

    /**
     * Release a connection back to the pool
     */
    public function release($connection): void
    {
        if (! $connection) {
            return;
        }

        try {
            $this->resetConnection($connection);
            $this->markIdle($connection);

            $pushTimeout = $this->config['release_timeout'] ?? 1.0;
            $pushed = $this->channel->push($connection, $pushTimeout);

            if (! $pushed) {
                error_log('⚠️ DB pool release timeout - closing connection instead');
                $this->markBorrowed($connection);
                $this->closeConnection($connection);
                $this->currentConnections--;
            }
        } catch (Throwable $e) {
            error_log('❌ Error releasing connection to pool: '.$e->getMessage());
            // Try to close the connection to prevent leaks
            $this->markBorrowed($connection);
            $this->closeConnection($connection);
            $this->currentConnections--;
        }

        $this->pruneIdleConnections();
    }

    /**
     * Close idle pooled connections above min_connections.
     */
    public function pruneIdleConnections(?float $now = null): int
    {
        $maxIdleTime = (float) ($this->config['max_idle_time'] ?? 60.0);

        if ($maxIdleTime <= 0 || $this->currentConnections <= ($this->config['min_connections'] ?? 1)) {
            return 0;
        }

        $now ??= microtime(true);
        $minConnections = (int) ($this->config['min_connections'] ?? 1);
        $available = $this->channel->length();
        $kept = [];
        $closed = 0;

        for ($i = 0; $i < $available; $i++) {
            $connection = $this->channel->pop(0.001);

            if ($connection === false) {
                break;
            }

            $connectionId = spl_object_id($connection);
            $idleSince = $this->idleSince[$connectionId] ?? $now;
            $idleFor = $now - $idleSince;

            if ($this->currentConnections > $minConnections && $idleFor >= $maxIdleTime) {
                unset($this->idleSince[$connectionId]);
                $this->closeConnection($connection);
                $this->currentConnections--;
                $closed++;

                continue;
            }

            $kept[] = $connection;
        }

        foreach ($kept as $connection) {
            $this->channel->push($connection, 0.001);
        }

        return $closed;
    }

    /**
     * Reset connection state to prevent state leaks between requests.
     */
    protected function resetConnection($connection): void
    {
        try {
            // Check if there's an active transaction and roll it back
            if ($connection instanceof Connection) {
                // Roll back any open transaction through Laravel's Connection
                // object so both PDO and Laravel's transaction counter reset.
                if ($connection->transactionLevel() > 0) {
                    error_log('⚠️ Rolling back uncommitted Laravel transaction before returning to pool');
                    $connection->rollBack(0);
                }

                // If the PDO is still in a transaction, force rollback as a
                // final guard for drivers or edge cases outside Laravel state.
                $pdo = $connection->getPdo();

                if ($pdo && $pdo->inTransaction()) {
                    error_log('⚠️ Rolling back uncommitted PDO transaction before returning to pool');
                    $pdo->rollBack();
                }

                // Reset the query log
                $connection->flushQueryLog();

                if (method_exists($connection, 'forgetRecordModificationState')) {
                    $connection->forgetRecordModificationState();
                } elseif (method_exists($connection, 'recordsHaveBeenModified')) {
                    $connection->recordsHaveBeenModified(false);
                }

                if (method_exists($connection, 'setReadWriteType')) {
                    $connection->setReadWriteType(null);
                }

                // Reset session variables for MySQL
                $driver = $connection->getDriverName();
                if (in_array($driver, ['mysql', 'mariadb'])) {
                    try {
                        // Reset session state
                        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                        $pdo->exec('SET autocommit = 1');
                    } catch (Throwable $e) {
                        // Non-critical, log and continue
                        error_log('⚠️ Could not reset MySQL session: '.$e->getMessage());
                    }
                }

                // For PostgreSQL
                if ($driver === 'pgsql') {
                    try {
                        $pdo->exec('RESET ALL');
                    } catch (Throwable $e) {
                        error_log('⚠️ Could not reset PostgreSQL session: '.$e->getMessage());
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('❌ Error resetting connection state: '.$e->getMessage());
            // If reset fails, the connection may be in a bad state
            throw $e;
        }
    }

    /**
     * Safely close a connection
     */
    protected function closeConnection($connection): void
    {
        try {
            if ($connection instanceof Connection) {
                $connection->disconnect();
            }
        } catch (Throwable $e) {
            error_log('⚠️ Error closing connection: '.$e->getMessage());
        }
    }

    /**
     * Create a new database connection
     */
    protected function createConnection()
    {
        $this->currentConnections++;

        try {
            return $this->factory->make($this->connectionConfig, $this->name);
        } catch (Throwable $e) {
            $this->currentConnections--;

            throw $e;
        }
    }

    protected function markIdle($connection): void
    {
        if (is_object($connection)) {
            $this->idleSince[spl_object_id($connection)] = microtime(true);
        }
    }

    protected function markBorrowed($connection): void
    {
        if (is_object($connection)) {
            unset($this->idleSince[spl_object_id($connection)]);
        }
    }

    /**
     * Check if a connection is still valid
     */
    protected function checkConnection($connection): bool
    {
        try {
            // Ping the database
            $connection->getPdo()->query('SELECT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Reconnect a stale connection
     */
    protected function reconnect($connection)
    {
        try {
            $connection->reconnect();
            return $connection;
        } catch (Throwable $e) {
            // If reconnect fails, create a new one
            $this->markBorrowed($connection);
            $this->closeConnection($connection);
            $this->currentConnections--;
            return $this->createConnection();
        }
    }

    /**
     * Flush idle connections from the pool
     */
    public function flush(): void
    {
        $minConnections = $this->config['min_connections'] ?? 1;

        while ($this->currentConnections > $minConnections) {
            $connection = $this->channel->pop(0.001);

            if ($connection === false) {
                break; // No more connections in channel
            }

            $this->markBorrowed($connection);
            $this->closeConnection($connection);
            $this->currentConnections--;
        }
    }

    /**
     * Get pool statistics
     */
    public function getStats(): array
    {
        return [
            'current_connections' => $this->currentConnections,
            'available_connections' => $this->channel->length(),
            'idle_tracked_connections' => count($this->idleSince),
            'max_connections' => $this->config['max_connections'] ?? 10,
            'min_connections' => $this->config['min_connections'] ?? 1,
            'max_idle_time' => $this->config['max_idle_time'] ?? 60.0,
        ];
    }

    protected function startIdlePruner(): void
    {
        $heartbeat = (float) ($this->config['heartbeat'] ?? -1);
        $maxIdleTime = (float) ($this->config['max_idle_time'] ?? 60.0);

        if ($heartbeat <= 0 || $maxIdleTime <= 0 || $this->idlePruneTimerId !== null) {
            return;
        }

        if (! class_exists(Timer::class)) {
            return;
        }

        $intervalMs = max(1000, (int) round($heartbeat * 1000));
        $this->idlePruneTimerId = Timer::tick($intervalMs, function (): void {
            $this->pruneIdleConnections();
        });
    }
}
