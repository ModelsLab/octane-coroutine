<?php

namespace Laravel\Octane\Swoole\Coroutine;

/**
 * Coordinator Manager
 * 
 * Manages multiple coordinators for different lifecycle events.
 * Inspired by Hyperf's CoordinatorManager for production-grade
 * worker lifecycle management.
 */
class CoordinatorManager
{
    /**
     * Coordinator instances
     */
    private static array $coordinators = [];
    
    /**
     * Coordinator event identifiers
     */
    public const WORKER_START = 'worker.start';
    public const WORKER_EXIT = 'worker.exit';
    public const WORKER_ERROR = 'worker.error';
    public const REQUEST_START = 'request.start';
    public const REQUEST_END = 'request.end';
    
    /**
     * Get or create a coordinator for the given identifier
     *
     * @param  string  $identifier
     * @return Coordinator
     */
    public static function until(string $identifier): Coordinator
    {
        if (!isset(static::$coordinators[$identifier])) {
            static::$coordinators[$identifier] = new Coordinator();
        }
        
        return static::$coordinators[$identifier];
    }
    
    /**
     * Clear a specific coordinator
     *
     * @param  string  $identifier
     * @return void
     */
    public static function clear(string $identifier): void
    {
        unset(static::$coordinators[$identifier]);
    }
    
    /**
     * Clear all coordinators
     *
     * @return void
     */
    public static function clearAll(): void
    {
        static::$coordinators = [];
    }
    
    /**
     * Get all registered coordinator identifiers
     *
     * @return array
     */
    public static function getRegistered(): array
    {
        return array_keys(static::$coordinators);
    }
    
    /**
     * Check if a coordinator exists
     *
     * @param  string  $identifier
     * @return bool
     */
    public static function has(string $identifier): bool
    {
        return isset(static::$coordinators[$identifier]);
    }
}
