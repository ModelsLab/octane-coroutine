<?php

namespace Laravel\Octane\Swoole\Coroutine;

use Swoole\Coroutine;

/**
 * Coroutine Monitor
 * 
 * Provides monitoring and debugging utilities for coroutines.
 * Inspired by Hyperf's monitoring capabilities for production debugging.
 */
class Monitor
{
    /**
     * Get comprehensive coroutine statistics
     *
     * @return array
     */
    public static function stats(): array
    {
        if (!extension_loaded('swoole')) {
            return [
                'enabled' => false,
                'error' => 'Swoole extension not loaded',
            ];
        }
        
        $stats = Coroutine::stats();
        
        return [
            'enabled' => true,
            'active_coroutines' => $stats['coroutine_num'] ?? 0,
            'peak_coroutines' => $stats['coroutine_peak_num'] ?? 0,
            'event_count' => $stats['event_num'] ?? 0,
            'signal_count' => $stats['signal_listener_num'] ?? 0,
            'aio_task_count' => $stats['aio_task_num'] ?? 0,
            'c_stack_size' => $stats['c_stack_size'] ?? 0,
            'coroutine_stack_size' => $stats['coroutine_stack_size'] ?? 0,
        ];
    }
    
    /**
     * List all active coroutine IDs
     *
     * @return array
     */
    public static function listCoroutines(): array
    {
        if (!extension_loaded('swoole')) {
            return [];
        }
        
        $iterator = Coroutine::list();
        return iterator_to_array($iterator);
    }
    
    /**
     * Get backtrace for a specific coroutine
     *
     * @param  int  $cid  Coroutine ID
     * @param  int  $options  Debug backtrace options
     * @param  int  $limit  Limit number of stack frames
     * @return array
     */
    public static function getBacktrace(int $cid, int $options = DEBUG_BACKTRACE_PROVIDE_OBJECT, int $limit = 0): array
    {
        if (!extension_loaded('swoole')) {
            return [];
        }
        
        return Coroutine::getBackTrace($cid, $options, $limit);
    }
    
    /**
     * Get detailed info about all active coroutines
     *
     * @return array
     */
    public static function getCoroutineInfo(): array
    {
        $coroutines = static::listCoroutines();
        $info = [];
        
        foreach ($coroutines as $cid) {
            $info[$cid] = [
                'id' => $cid,
                'backtrace' => static::getBacktrace($cid, DEBUG_BACKTRACE_IGNORE_ARGS, 5),
            ];
        }
        
        return $info;
    }
    
    /**
     * Check if currently running in a coroutine
     *
     * @return bool
     */
    public static function inCoroutine(): bool
    {
        if (!extension_loaded('swoole')) {
            return false;
        }
        
        return Coroutine::getCid() >= 0;
    }
    
    /**
     * Get current coroutine ID
     *
     * @return int -1 if not in coroutine
     */
    public static function getCurrentId(): int
    {
        if (!extension_loaded('swoole')) {
            return -1;
        }
        
        return Coroutine::getCid();
    }
    
    /**
     * Get a formatted report of coroutine status
     *
     * @return string
     */
    public static function getReport(): string
    {
        $stats = static::stats();
        
        if (!$stats['enabled']) {
            return "Coroutine monitoring disabled: {$stats['error']}";
        }
        
        $report = "=== Coroutine Monitor Report ===\n";
        $report .= "Active Coroutines: {$stats['active_coroutines']}\n";
        $report .= "Peak Coroutines: {$stats['peak_coroutines']}\n";
        $report .= "Event Listeners: {$stats['event_count']}\n";
        $report .= "Signal Listeners: {$stats['signal_count']}\n";
        $report .= "AIO Tasks: {$stats['aio_task_count']}\n";
        $report .= "Current Coroutine ID: " . static::getCurrentId() . "\n";
        
        if ($stats['active_coroutines'] > 0) {
            $report .= "\nActive Coroutine IDs: ";
            $report .= implode(', ', static::listCoroutines()) . "\n";
        }
        
        return $report;
    }
}
