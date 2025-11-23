<?php

namespace Laravel\Octane\Swoole\Coroutine;

use Swoole\Coroutine;

/**
 * Coroutine Context Manager
 * 
 * Pure Swoole implementation - NO Hyperf dependencies!
 * Ensures data isolation between concurrent coroutines.
 */
class Context
{
    /**
     * Global context for non-coroutine environments
     */
    private static array $globalContext = [];

    /**
     * Get data from coroutine context
     *
     * @param  string  $key
     * @param  mixed  $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $cid = Coroutine::getCid();
        
        if ($cid < 0) {
            return static::$globalContext[$key] ?? $default;
        }
        
        $context = Coroutine::getContext($cid);
        return $context[$key] ?? $default;
    }
    
    /**
     * Set data in coroutine context
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    public static function set(string $key, $value): void
    {
        $cid = Coroutine::getCid();
        
        if ($cid < 0) {
            static::$globalContext[$key] = $value;
            return;
        }
        
        $context = Coroutine::getContext($cid);
        $context[$key] = $value;
    }
    
    /**
     * Check if key exists in context
     *
     * @param  string  $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        $cid = Coroutine::getCid();
        
        if ($cid < 0) {
            return isset(static::$globalContext[$key]);
        }
        
        $context = Coroutine::getContext($cid);
        return isset($context[$key]);
    }
    
    /**
     * Remove a value from the context
     *
     * @param  string  $key
     * @return void
     */
    public static function delete(string $key): void
    {
        $cid = Coroutine::getCid();
        
        if ($cid < 0) {
            unset(static::$globalContext[$key]);
            return;
        }
        
        $context = Coroutine::getContext($cid);
        unset($context[$key]);
    }
    
    /**
     * Clear all context data for current coroutine
     *
     * @return void
     */
    public static function clear(): void
    {
        $cid = Coroutine::getCid();
        
        if ($cid < 0) {
            static::$globalContext = [];
            return;
        }
        
        $context = Coroutine::getContext($cid);
        foreach ($context as $key => $_) {
            unset($context[$key]);
        }
    }
    
    /**
     * Get all context data for current coroutine
     *
     * @return array
     */
    public static function all(): array
    {
        $cid = Coroutine::getCid();
        
        if ($cid < 0) {
            return static::$globalContext;
        }
        
        $context = Coroutine::getContext($cid);
        return iterator_to_array($context);
    }
    
    /**
     * Get current coroutine ID (for debugging)
     *
     * @return int -1 if not in coroutine
     */
    public static function id(): int
    {
        return Coroutine::getCid();
    }
    
    /**
     * Check if currently running in a coroutine
     *
     * @return bool
     */
    public static function inCoroutine(): bool
    {
        return Coroutine::getCid() > 0;
    }
}
