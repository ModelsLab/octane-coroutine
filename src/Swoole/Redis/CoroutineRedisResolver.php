<?php

namespace Laravel\Octane\Swoole\Redis;

use Illuminate\Redis\RedisManager as BaseRedisManager;

/**
 * The manager CoroutineRedisManager opens coroutine connections through.
 *
 * With the worker's Redis pool enabled it creates poolable phpredis connections.
 */
class CoroutineRedisResolver extends BaseRedisManager
{
    /**
     * Whether connections are created for the worker's Redis pool.
     *
     * @var bool
     */
    public bool $pooled = false;

    /**
     * Get the connector instance for the current driver.
     *
     * @return \Illuminate\Contracts\Redis\Connector|null
     */
    protected function connector()
    {
        if ($this->pooled && $this->driver === 'phpredis' && ! isset($this->customCreators['phpredis'])) {
            return new PooledPhpRedisConnector;
        }

        return parent::connector();
    }
}
