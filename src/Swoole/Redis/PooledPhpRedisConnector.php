<?php

namespace Laravel\Octane\Swoole\Redis;

use Illuminate\Redis\Connectors\PhpRedisConnector;

/**
 * Creates phpredis connections that the worker's Redis pool can take back.
 */
class PooledPhpRedisConnector extends PhpRedisConnector
{
    /**
     * Create a new connection.
     *
     * @param  array  $config
     * @param  array  $options
     * @return \Laravel\Octane\Swoole\Redis\PooledPhpRedisConnection
     */
    public function connect(array $config, array $options)
    {
        return PooledPhpRedisConnection::fromConnection(parent::connect($config, $options));
    }
}
