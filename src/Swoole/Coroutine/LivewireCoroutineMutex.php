<?php

namespace Laravel\Octane\Swoole\Coroutine;

use Swoole\Coroutine\Channel;

class LivewireCoroutineMutex
{
    private const DEPTH_KEY = 'octane.livewire_mutex_depth';

    /**
     * Seconds to wait for the Livewire render lock before healing a stuck channel.
     *
     * A cancelled/timed-out coroutine can skip finally blocks and leave the
     * channel empty, which otherwise deadlocks every later Livewire request
     * on this worker (including file-upload updates).
     */
    private const ACQUIRE_TIMEOUT_SECONDS = 30.0;

    private static $channel = null;

    public function acquire(): void
    {
        if (! $this->shouldLock()) {
            return;
        }

        $depth = (int) Context::get(self::DEPTH_KEY, 0);

        if ($depth > 0) {
            Context::set(self::DEPTH_KEY, $depth + 1);

            return;
        }

        $token = $this->channel()->pop(self::ACQUIRE_TIMEOUT_SECONDS);

        if ($token === false) {
            error_log('⚠️ LivewireCoroutineMutex acquire timed out after '.self::ACQUIRE_TIMEOUT_SECONDS.'s; resetting lock to unblock Livewire');
            $this->forceReset();
            $this->channel()->pop(0.01);
        }

        Context::set(self::DEPTH_KEY, 1);
    }

    public function release(): void
    {
        if (! $this->shouldLock()) {
            return;
        }

        $depth = (int) Context::get(self::DEPTH_KEY, 0);

        if ($depth <= 0) {
            return;
        }

        if ($depth > 1) {
            Context::set(self::DEPTH_KEY, $depth - 1);

            return;
        }

        Context::delete(self::DEPTH_KEY);

        $this->channel()->push(true);
    }

    public function releaseAllForCurrentCoroutine(): void
    {
        if (! $this->shouldLock()) {
            return;
        }

        while ((int) Context::get(self::DEPTH_KEY, 0) > 0) {
            $this->release();
        }
    }

    /**
     * Re-seed the lock after a cancelled coroutine may have leaked it.
     */
    public function forceReset(): void
    {
        if (! class_exists(Channel::class)) {
            return;
        }

        $channel = $this->channel();

        while ($channel->pop(0.001) !== false) {
            // Drain any residual tokens before re-seeding.
        }

        $channel->push(true);
    }

    /**
     * @template TValue
     *
     * @param  callable(): TValue  $callback
     * @return TValue
     */
    public function synchronized(callable $callback): mixed
    {
        $this->acquire();

        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    private function shouldLock(): bool
    {
        return class_exists(\Swoole\Coroutine::class)
            && class_exists(Channel::class)
            && Context::inCoroutine();
    }

    private function channel(): Channel
    {
        if (! self::$channel instanceof Channel) {
            self::$channel = new Channel(1);
            self::$channel->push(true);
        }

        return self::$channel;
    }
}
