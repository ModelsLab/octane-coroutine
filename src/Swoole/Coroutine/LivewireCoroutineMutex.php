<?php

namespace Laravel\Octane\Swoole\Coroutine;

use Swoole\Coroutine\Channel;

class LivewireCoroutineMutex
{
    private const DEPTH_KEY = 'octane.livewire_mutex_depth';

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

        $this->channel()->pop();

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
