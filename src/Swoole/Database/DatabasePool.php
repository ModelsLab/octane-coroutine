<?php

namespace Laravel\Octane\Swoole\Database;

use Swoole\Coroutine\Channel;
use Illuminate\Database\Connectors\ConnectionFactory;
use Throwable;

/**
 * Custom Connection Pool for Laravel
 * 
 * Inspired by Hyperf's Pool architecture but designed for Laravel compatibility.
 * Uses Swoole Channels for coroutine-safe connection pooling.
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
            $this->channel->push($this->createConnection());
        }
    }

    /**
     * Get a connection from the pool
     */
    public function get()
    {
        $waitTimeout = $this->config['wait_timeout'] ?? 3.0;
        
        // Try to get from pool first
        $connection = $this->channel->pop($waitTimeout);
        
        if ($connection === false) {
            // Channel is empty and timeout reached
            // Try to create a new connection if under max limit
            if ($this->currentConnections < ($this->config['max_connections'] ?? 10)) {
                $connection = $this->createConnection();
            } else {
                throw new \RuntimeException('Connection pool exhausted. Cannot establish new connection before wait_timeout.');
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
        if ($connection) {
            $this->channel->push($connection);
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
            
            try {
                $connection->disconnect();
            } catch (Throwable $e) {
                // Ignore disconnect errors
            }
            
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
