<?php

namespace Laravel\Octane\Swoole\Database;

use Swoole\Coroutine\Channel;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Connection;
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
                $this->channel->push($this->createConnection());
            } catch (Throwable $e) {
                error_log("❌ Failed to create initial pool connection: " . $e->getMessage());
            }
        }
    }

    /**
     * Get a connection from the pool
     */
    public function get()
    {
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

        // Check if connection is still valid
        if (!$this->checkConnection($connection)) {
            $connection = $this->reconnect($connection);
        }

        return $connection;
    }

    /**
     * Release a connection back to the pool
     */
    public function release($connection): void
    {
        if (!$connection) {
            return;
        }

        try {
            $this->resetConnection($connection);

            $pushTimeout = $this->config['release_timeout'] ?? 1.0;
            $pushed = $this->channel->push($connection, $pushTimeout);

            if (!$pushed) {
                error_log("⚠️ DB pool release timeout - closing connection instead");
                $this->closeConnection($connection);
                $this->currentConnections--;
            }
        } catch (Throwable $e) {
            error_log("❌ Error releasing connection to pool: " . $e->getMessage());
            // Try to close the connection to prevent leaks
            $this->closeConnection($connection);
            $this->currentConnections--;
        }
    }

    /**
     * Reset connection state to prevent state leaks between requests.
     */
    protected function resetConnection($connection): void
    {
        try {
            // Check if there's an active transaction and roll it back
            if ($connection instanceof Connection) {
                // Roll back any open transaction
                $pdo = $connection->getPdo();

                if ($pdo && $pdo->inTransaction()) {
                    error_log("⚠️ Rolling back uncommitted transaction before returning to pool");
                    $pdo->rollBack();
                }

                // Reset the query log
                $connection->flushQueryLog();

                // Reset session variables for MySQL
                $driver = $connection->getDriverName();
                if (in_array($driver, ['mysql', 'mariadb'])) {
                    try {
                        // Reset session state
                        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                        $pdo->exec('SET autocommit = 1');
                    } catch (Throwable $e) {
                        // Non-critical, log and continue
                        error_log("⚠️ Could not reset MySQL session: " . $e->getMessage());
                    }
                }

                // For PostgreSQL
                if ($driver === 'pgsql') {
                    try {
                        $pdo->exec('RESET ALL');
                    } catch (Throwable $e) {
                        error_log("⚠️ Could not reset PostgreSQL session: " . $e->getMessage());
                    }
                }
            }
        } catch (Throwable $e) {
            error_log("❌ Error resetting connection state: " . $e->getMessage());
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
            error_log("⚠️ Error closing connection: " . $e->getMessage());
        }
    }

    /**
     * Create a new database connection
     */
    protected function createConnection()
    {
        $this->currentConnections++;
        return $this->factory->make($this->connectionConfig, $this->name);
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
            'max_connections' => $this->config['max_connections'] ?? 10,
            'min_connections' => $this->config['min_connections'] ?? 1,
        ];
    }
}
