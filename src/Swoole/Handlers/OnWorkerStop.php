<?php

namespace Laravel\Octane\Swoole\Handlers;

use Laravel\Octane\Swoole\Coroutine\CoordinatorManager;
use Laravel\Octane\Swoole\Coroutine\Monitor;
use Swoole\Coroutine;

/**
 * OnWorkerStop Handler
 *
 * Handles graceful worker shutdown, ensuring in-flight coroutines
 * complete before the worker exits.
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

        $initialRequests = Monitor::getActiveRequestCount();

        if ($initialRequests > 0) {
            error_log("⏳ {$workerType} #{$workerId} waiting for {$initialRequests} active requests to complete...");
        }

        // Wait for active requests to complete (with timeout)
        $waited = 0;
        $checkInterval = 0.1; // Check every 100ms

        while ($waited < $this->maxShutdownWait) {
            $activeRequests = Monitor::getActiveRequestCount();

            // If no more request coroutines, we're done
            if ($activeRequests === 0) {
                break;
            }

            // Log progress every 5 seconds
            if (fmod($waited, 5.0) < $checkInterval) {
                error_log("⏳ {$workerType} #{$workerId} still waiting: {$activeRequests} requests active (waited: {$waited}s)");
            }

            Coroutine::sleep($checkInterval);
            $waited += $checkInterval;
        }

        $finalRequests = Monitor::getActiveRequestCount();

        if ($finalRequests > 0) {
            error_log("⚠️  {$workerType} #{$workerId} timeout reached: {$finalRequests} requests still active (waited: {$waited}s)");

            // Log which coroutines are still active for debugging
            $activeIds = Monitor::getActiveRequestCoroutines();
            if (!empty($activeIds)) {
                error_log("🔍 Active request coroutine IDs: " . implode(', ', $activeIds));
            }
        } else {
            error_log("✅ {$workerType} #{$workerId} graceful shutdown complete (waited: {$waited}s)");
        }

        // Clear tracked request coroutines
        Monitor::clearRequestCoroutines();

        // Clear coordinators for this worker
        CoordinatorManager::clearAll();
    }
}
