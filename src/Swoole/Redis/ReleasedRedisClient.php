<?php

namespace Laravel\Octane\Swoole\Redis;

use LogicException;

/**
 * Stands in for a phpredis socket after its lease went back to the worker's pool.
 *
 * Code that kept a connection past the end of its request would otherwise talk on a socket
 * another request is now using. It gets this error instead.
 */
class ReleasedRedisClient
{
    /**
     * Closing a released connection is a no-op; the socket belongs to the pool.
     *
     * @return bool
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * Report that the released connection is not usable.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return false;
    }

    /**
     * Refuse every other call.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return never
     */
    public function __call($method, $parameters)
    {
        throw new LogicException(
            "Redis connection used after its request ended (calling {$method}()); it went back to the worker pool. Resolve a new one with Redis::connection()."
        );
    }
}
