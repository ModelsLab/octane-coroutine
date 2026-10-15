<?php

namespace Laravel\Octane\Swoole\Redis;

use Redis;
use Throwable;
use WeakMap;

/**
 * Idle phpredis sockets kept by one worker process between requests.
 *
 * Without it every request coroutine opened its own connections (connect, AUTH and
 * SELECT each time) and closed them at the end, so each request paid those round trips
 * and closed sockets piled up in TIME_WAIT until the ephemeral port range ran out.
 *
 * A socket is only ever used by one coroutine: a request borrows it inside a new lease
 * (PooledPhpRedisConnection) and the worker returns it after the request is torn down. It
 * comes back only if it is provably clean — connected, not inside MULTI or a pipeline, on
 * the database and options it was created with, and with no unfinished or state-changing
 * command — otherwise it is closed. The returned lease loses the socket, so a reference
 * kept past the request throws instead of sharing it. Borrowing never waits: with nothing
 * idle the caller opens a new connection, as before.
 */
class RedisConnectionPool
{
    /**
     * Idle sockets by pool key, oldest release first.
     *
     * @var array<string, list<array{client: \Redis, connector: ?callable, config: array, created: float, released: float, state: array}>>
     */
    protected array $idle = [];

    /**
     * Creation time and state snapshot of the leases that are out.
     *
     * @var \WeakMap<\Laravel\Octane\Swoole\Redis\PooledPhpRedisConnection, array{0: float, 1: array}>
     */
    protected WeakMap $loaned;

    /**
     * The process the pool belongs to.
     *
     * @var int
     */
    protected int $pid;

    /**
     * Create a new pool.
     *
     * @param  int  $maxIdle  Idle sockets kept per pool key.
     * @param  float  $maxIdleTime  Seconds an idle socket may wait before it is closed.
     * @param  float  $pingAfterIdle  Seconds idle after which a socket is pinged before reuse.
     * @param  float  $maxLifetime  Seconds after which a socket is closed instead of reused.
     */
    public function __construct(
        protected int $maxIdle = 16,
        protected float $maxIdleTime = 60.0,
        protected float $pingAfterIdle = 5.0,
        protected float $maxLifetime = 300.0,
    ) {
        $this->loaned = new WeakMap;
        $this->pid = getmypid();
    }

    /**
     * Create a pool from the `octane.swoole.redis_pool` configuration.
     *
     * @param  array  $config
     * @return static
     */
    public static function fromConfig(array $config): static
    {
        return new static(
            max(0, (int) ($config['max_idle'] ?? 16)),
            (float) ($config['max_idle_time'] ?? 60),
            (float) ($config['ping_after_idle'] ?? 5),
            (float) ($config['max_lifetime'] ?? 300),
        );
    }

    /**
     * Lend an idle socket for the given key in a new lease, if a healthy one is available.
     *
     * @param  string  $key
     * @return \Laravel\Octane\Swoole\Redis\PooledPhpRedisConnection|null
     */
    public function borrow(string $key): ?PooledPhpRedisConnection
    {
        $this->forgetSocketsFromAnotherProcess();
        $this->pruneExpired($key);

        while (($entry = array_pop($this->idle[$key])) !== null) {
            $now = microtime(true);

            if ($now - $entry['created'] >= $this->maxLifetime) {
                $this->close($entry['client']);

                continue;
            }

            if ($now - $entry['released'] >= $this->pingAfterIdle && ! $this->ping($entry['client'])) {
                $this->close($entry['client']);

                continue;
            }

            $lease = new PooledPhpRedisConnection($entry['client'], $entry['connector'], $entry['config']);
            $this->loaned[$lease] = [$entry['created'], $entry['state']];

            return $lease;
        }

        return null;
    }

    /**
     * Start tracking a connection that was just opened.
     *
     * @param  \Laravel\Octane\Swoole\Redis\PooledPhpRedisConnection  $connection
     * @return void
     */
    public function track(PooledPhpRedisConnection $connection): void
    {
        if (($state = $this->state($connection->client())) !== null) {
            $this->loaned[$connection] = [microtime(true), $state];
        }
    }

    /**
     * Take a socket back from its lease. Returns false when the caller must close it instead.
     *
     * @param  string  $key
     * @param  \Laravel\Octane\Swoole\Redis\PooledPhpRedisConnection  $connection
     * @return bool
     */
    public function release(string $key, PooledPhpRedisConnection $connection): bool
    {
        $loan = $this->loaned[$connection] ?? null;
        unset($this->loaned[$connection]);

        if ($loan === null || $this->pid !== getmypid()) {
            return false;
        }

        [$created, $state] = $loan;
        $now = microtime(true);

        $this->pruneExpired($key);

        if (count($this->idle[$key] ?? []) >= $this->maxIdle
            || $now - $created >= $this->maxLifetime
            || ! $connection->canReturnToPool()
            || $this->state($connection->client()) !== $state) {
            return false;
        }

        $connector = $connection->connector();
        $config = $connection->config();

        $this->idle[$key][] = [
            'client' => $connection->detachClient(),
            'connector' => $connector,
            'config' => $config,
            'created' => $created,
            'released' => $now,
            'state' => $state,
        ];

        return true;
    }

    /**
     * Close every idle socket.
     *
     * @return void
     */
    public function flush(): void
    {
        foreach ($this->idle as $entries) {
            foreach ($entries as $entry) {
                $this->close($entry['client']);
            }
        }

        $this->idle = [];
    }

    /**
     * The number of idle sockets for the given key.
     *
     * @param  string  $key
     * @return int
     */
    public function idleCount(string $key): int
    {
        return count($this->idle[$key] ?? []);
    }

    /**
     * Close idle sockets that waited too long; the oldest releases sit at the bottom.
     *
     * @param  string  $key
     * @return void
     */
    protected function pruneExpired(string $key): void
    {
        $this->idle[$key] ??= [];
        $now = microtime(true);

        while ($this->idle[$key] !== []
            && ($now - $this->idle[$key][0]['released'] >= $this->maxIdleTime
                || $now - $this->idle[$key][0]['created'] >= $this->maxLifetime)) {
            $this->close(array_shift($this->idle[$key])['client']);
        }
    }

    /**
     * Read the socket state a clean connection must keep, or null if it is not usable.
     *
     * @param  mixed  $client
     * @return array|null
     */
    protected function state($client): ?array
    {
        try {
            if (! $client instanceof Redis || ! $client->isConnected() || $client->getMode() !== Redis::ATOMIC) {
                return null;
            }

            $state = [$client->getDbNum()];

            foreach ([
                'OPT_PREFIX', 'OPT_SERIALIZER', 'OPT_COMPRESSION', 'OPT_READ_TIMEOUT', 'OPT_SCAN',
                'OPT_REPLY_LITERAL', 'OPT_NULL_MULTIBULK_AS_NULL', 'OPT_MAX_RETRIES',
            ] as $option) {
                if (defined(Redis::class.'::'.$option)) {
                    $state[$option] = $client->getOption(constant(Redis::class.'::'.$option));
                }
            }

            return $state;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Check that an idle socket still answers.
     *
     * @param  \Redis  $client
     * @return bool
     */
    protected function ping($client): bool
    {
        try {
            $reply = $client->ping();

            return $reply === true || $reply === '+PONG' || $reply === 'PONG';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Close a socket, ignoring transport errors.
     *
     * @param  mixed  $client
     * @return void
     */
    protected function close($client): void
    {
        try {
            $client->close();
        } catch (Throwable) {
            // The socket is being discarded either way.
        }
    }

    /**
     * Drop sockets inherited across a fork; they belong to the parent process.
     *
     * @return void
     */
    protected function forgetSocketsFromAnotherProcess(): void
    {
        if ($this->pid === getmypid()) {
            return;
        }

        $this->idle = [];
        $this->loaned = new WeakMap;
        $this->pid = getmypid();
    }
}
