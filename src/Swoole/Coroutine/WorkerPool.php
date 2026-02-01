<?php

namespace Laravel\Octane\Swoole\Coroutine;

use Closure;
use Swoole\Coroutine\Channel;

class WorkerPool
{
    protected int $currentSize = 0;
    protected int $nextIndex = 0;
    protected array $metadata = [];
    protected int $idleTimeout;
    protected Closure $factory;

    public function __construct(
        protected Channel $channel,
        protected int $minSize,
        protected int $maxSize,
        callable $factory,
        protected PoolLock $lock = new NullPoolLock,
        int $idleTimeout = 10
    ) {
        $this->factory = Closure::fromCallable($factory);
        $this->minSize = max(0, $this->minSize);
        $this->maxSize = max($this->minSize, $this->maxSize);
        $this->idleTimeout = min(60, max(1, (int) $idleTimeout));
    }

    public function seed(int $count): void
    {
        $seedCount = max(0, min($this->maxSize, $count));

        for ($i = 0; $i < $seedCount; $i++) {
            $worker = $this->createWorker();

            if (! $worker) {
                continue;
            }

            $this->channel->push($worker);
        }
    }

    public function acquire(float $waitTimeout, bool $rejectOnFull): ?object
    {
        $worker = $this->tryAcquireWorker(0.1);

        if ($worker === false && $this->currentSize < $this->maxSize) {
            $worker = $this->grow();
        }

        if (($worker === false || $worker === null) && ! $rejectOnFull && $waitTimeout > 0) {
            $worker = $this->tryAcquireWorker($waitTimeout);
        }

        if (is_object($worker)) {
            $workerId = spl_object_id($worker);

            if (isset($this->metadata[$workerId])) {
                $this->metadata[$workerId]['last_acquired_at'] = microtime(true);
            }
        }

        return $worker === false ? null : $worker;
    }

    /**
     * Try to acquire a worker, shrinking idle workers if needed.
     *
     * Workers that have been idle longer than idleTimeout are terminated
     * (if pool is above minSize) to naturally shrink the pool during
     * low-traffic periods.
     */
    protected function tryAcquireWorker(float $timeout): mixed
    {
        $startTime = microtime(true);
        $remainingTimeout = $timeout;

        while ($remainingTimeout > 0) {
            $worker = $this->channel->pop($remainingTimeout);

            if ($worker === false) {
                return false;
            }

            $workerId = spl_object_id($worker);
            $now = microtime(true);

            // Check if this worker has been idle too long
            if (isset($this->metadata[$workerId])) {
                $lastUsedAt = $this->metadata[$workerId]['last_used_at'] ?? $now;
                $idleTime = $now - $lastUsedAt;

                // Shrink: terminate idle workers above minSize
                if ($idleTime > $this->idleTimeout && $this->currentSize > $this->minSize) {
                    error_log("📉 WorkerPool::acquire() - Shrinking idle worker (idle: {$idleTime}s > {$this->idleTimeout}s, currentSize: {$this->currentSize} -> " . ($this->currentSize - 1) . ")");

                    if (method_exists($worker, 'terminate')) {
                        try {
                            $worker->terminate();
                        } catch (\Throwable $e) {
                            error_log("⚠️ WorkerPool::acquire() - Failed to terminate idle worker: " . $e->getMessage());
                        }
                    }

                    $this->currentSize = max($this->minSize, $this->currentSize - 1);
                    unset($this->metadata[$workerId]);

                    // Worker will be garbage collected, try to get another
                    $remainingTimeout = $timeout - (microtime(true) - $startTime);
                    continue;
                }
            }

            // Worker is fresh enough, return it
            return $worker;
        }

        return false;
    }

    public function release(object $worker): bool
    {
        $workerId = spl_object_id($worker);

        $available = $this->channel->length();
        $now = microtime(true);

        // Shrinking idle workers happens at acquire time, not release time.
        if (isset($this->metadata[$workerId])) {
            $this->metadata[$workerId]['last_used_at'] = $now;
            unset($this->metadata[$workerId]['last_acquired_at']);
        }

        $pushed = $this->channel->push($worker, 0.5);

        if (! $pushed) {
            error_log("❌ WorkerPool::release() - Channel push failed! Terminating worker. currentSize={$this->currentSize}");
            $this->currentSize = max($this->minSize, $this->currentSize - 1);
            unset($this->metadata[$workerId]);

            return false;
        }

        return true;
    }

    public function stats(): array
    {
        return [
            'current_size' => $this->currentSize,
            'available' => $this->channel->length(),
            'min_size' => $this->minSize,
            'max_size' => $this->maxSize,
            'next_index' => $this->nextIndex,
        ];
    }

    public function getChannel(): Channel
    {
        return $this->channel;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    protected function grow(): ?object
    {
        if (! $this->lock->acquire(0.1)) {
            error_log("⚠️ WorkerPool::grow() - Failed to acquire lock (contention)");
            return null;
        }

        try {
            if ($this->currentSize >= $this->maxSize) {
                error_log("⚠️ WorkerPool::grow() - Pool at max size ({$this->currentSize} >= {$this->maxSize})");
                return null;
            }

            error_log("🌱 WorkerPool::grow() - Growing pool from {$this->currentSize} to " . ($this->currentSize + 1));
            return $this->createWorker();
        } finally {
            $this->lock->release();
        }
    }

    protected function createWorker(): ?object
    {
        $poolIndex = $this->nextIndex;
        $worker = ($this->factory)($poolIndex);

        if (! is_object($worker)) {
            return null;
        }

        $timestamp = microtime(true);
        $this->metadata[spl_object_id($worker)] = [
            'pool_index' => $poolIndex,
            'created_at' => $timestamp,
            'last_used_at' => $timestamp,
            'last_acquired_at' => null,
        ];

        $this->currentSize++;
        $this->nextIndex++;

        return $worker;
    }
}
