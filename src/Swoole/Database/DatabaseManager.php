<?php

namespace Laravel\Octane\Swoole\Database;

use Illuminate\Database\DatabaseManager as BaseDatabaseManager;
use Laravel\Octane\Swoole\Coroutine\Context;

class DatabaseManager extends BaseDatabaseManager
{
    protected $pools = [];

    public function connection($name = null)
    {
        $name = $name ?: $this->getDefaultConnection();

        // If we are NOT in a coroutine, fallback to parent behavior (standard singleton connection)
        if (!Context::inCoroutine()) {
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

        return $connection;
    }

    protected function getPool($name)
    {
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
            ];

            $this->pools[$name] = new DatabasePool(
                $poolConfig, 
                $config, 
                $name, 
                $this->factory
            );
        }
        return $this->pools[$name];
    }

    public function releaseConnections()
    {
        if (!Context::inCoroutine()) {
            return;
        }

        // Get all context keys and release connections
        $allContext = Context::all();
        foreach ($allContext as $key => $value) {
            if (str_ends_with($key, '.pool')) {
                // Get the connection
                $connectionKey = str_replace('.pool', '', $key);
                $connection = Context::get($connectionKey);
                
                if ($connection && $value instanceof DatabasePool) {
                    // Release connection back to pool
                    $value->release($connection);
                }
                
                // Clean up context
                Context::delete($key);
                Context::delete($connectionKey);
            }
        }
    }
}
