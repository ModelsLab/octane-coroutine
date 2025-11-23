<?php

namespace Laravel\Octane\Swoole\Handlers;

use Laravel\Octane\Swoole\Coroutine\CoordinatorManager;
use Laravel\Octane\Swoole\Coroutine\Monitor;
use Swoole\Coroutine;

/**
 * OnWorkerStop Handler
 * 
 * Handles graceful worker shutdown, ensuring in-flight coroutines
 * complete before the worker exits. Inspired by Hyperf's graceful
 * shutdown pattern.
 */
class OnWorkerStop
{
    /**
     * Maximum time to wait for in-flight requests to complete (seconds)
     */
    protected int $maxShutdownWait;
    
    /**
     * Create a new OnWorkerStop handler
     *
     * @param  int  $maxShutdownWait  Maximum wait time in seconds
     */
    public function __construct(int $maxShutdownWait = 30)
    {
        $this->maxShutdownWait = $maxShutdownWait;
    }
    
    /**
     * Handle the "workerstop" Swoole event
     *
     * @param  \Swoole\Http\Server  $server
     * @param  int  $workerId
     * @return void
     */
    public function __invoke($server, int $workerId): void
    {
        $workerType = $workerId >= ($server->setting['worker_num'] ?? 0) ? 'TASK WORKER' : 'WORKER';
        
        error_log("🛑 {$workerType} #{$workerId} beginning graceful shutdown...");
        
        // Signal that worker is exiting - allows in-flight coroutines to check
        CoordinatorManager::until(CoordinatorManager::WORKER_EXIT)->resume();
        
        // Get initial coroutine count
        $stats = Monitor::stats();
        $initialCoroutines = $stats['active_coroutines'] ?? 0;
        
        if ($initialCoroutines > 1) { // More than just the main coroutine
            error_log("⏳ {$workerType} #{$workerId} waiting for {$initialCoroutines} active coroutines to complete...");
        }
        
        // Wait for active requests to complete (with timeout)
        $waited = 0;
        $checkInterval = 0.1; // Check every 100ms
        
        while ($waited < $this->maxShutdownWait) {
            $stats = Monitor::stats();
            $activeCoroutines = $stats['active_coroutines'] ?? 0;
            
            // If only main coroutine (or none) remain, we're done
            if ($activeCoroutines <= 1) {
                break;
            }
            
            // Log progress every 5 seconds
            if (fmod($waited, 5.0) < $checkInterval) {
                error_log("⏳ {$workerType} #{$workerId} still waiting: {$activeCoroutines} coroutines active (waited: {$waited}s)");
            }
            
            Coroutine::sleep($checkInterval);
            $waited += $checkInterval;
        }
        
        $finalStats = Monitor::stats();
        $finalCoroutines = $finalStats['active_coroutines'] ?? 0;
        
        if ($finalCoroutines > 1) {
            error_log("⚠️  {$workerType} #{$workerId} timeout reached: {$finalCoroutines} coroutines still active (waited: {$waited}s)");
            
            // Log which coroutines are still active for debugging
            $activeIds = Monitor::listCoroutines();
            if (!empty($activeIds)) {
                error_log("🔍 Active coroutine IDs: " . implode(', ', $activeIds));
            }
        } else {
            error_log("✅ {$workerType} #{$workerId} graceful shutdown complete (waited: {$waited}s)");
        }
        
        // Clear coordinators for this worker
        CoordinatorManager::clearAll();
    }
}
