<?php

namespace Laravel\Octane\Swoole\Actions;

use Laravel\Octane\Swoole\Coroutine\Monitor;
use Laravel\Octane\Swoole\WorkerState;
use Throwable;

class StopWorkerIfMaxRequestsExceeded
{
    public function __construct(
        protected mixed $server,
        protected WorkerState $workerState
    ) {
    }

    public function __invoke(): bool
    {
        if ($this->workerState->maxRequests <= 0 || $this->workerState->recycleTriggered) {
            return false;
        }

        $this->workerState->handledRequests++;

        if ($this->workerState->handledRequests >= $this->workerState->maxRequests) {
            $this->workerState->recycleRequested = true;
        }

        if (! $this->workerState->recycleRequested || Monitor::getActiveRequestCount() > 0) {
            return false;
        }

        $this->workerState->recycleTriggered = true;

        try {
            $stopped = $this->server->stop($this->workerState->workerId);
        } catch (Throwable $e) {
            $this->workerState->recycleTriggered = false;

            error_log("⚠️ Failed to recycle worker #{$this->workerState->workerId}: {$e->getMessage()}");

            return false;
        }

        if ($stopped === false) {
            $this->workerState->recycleTriggered = false;

            error_log("⚠️ Swoole refused to recycle worker #{$this->workerState->workerId}");

            return false;
        }

        error_log(
            "♻️ WORKER #{$this->workerState->workerId} recycling after ".
            "{$this->workerState->handledRequests}/{$this->workerState->maxRequests} handled requests"
        );

        return true;
    }
}
