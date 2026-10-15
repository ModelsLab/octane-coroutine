<?php

namespace Laravel\Octane\Swoole\Redis;

use Closure;
use Illuminate\Redis\Connections\PhpRedisConnection;

/**
 * A phpredis connection whose socket may go back to the worker's Redis pool.
 *
 * Each borrow gets a new instance (a lease) around the pooled socket. It records what
 * makes the socket unsafe for the next request: a command that did not finish (its reply
 * can still be on the wire), and commands that change connection state which nothing
 * restores (subscriptions, WATCH, SELECT, MULTI sent raw, CLIENT, AUTH, HELLO, ...). When
 * the socket goes back, the lease loses it: anything that kept the lease gets an error
 * instead of a socket another request is using.
 */
class PooledPhpRedisConnection extends PhpRedisConnection
{
    /**
     * Commands that leave state on the connection.
     *
     * @var list<string>
     */
    protected const STATEFUL_COMMANDS = [
        'auth', 'client', 'hello', 'monitor', 'psubscribe', 'punsubscribe', 'readonly',
        'readwrite', 'reset', 'select', 'setoption', 'ssubscribe', 'subscribe', 'sunsubscribe',
        'swapdb', 'unsubscribe', 'watch',
    ];

    /**
     * Commands that are only unsafe when sent raw, because phpredis does not track them.
     *
     * @var list<string>
     */
    protected const STATEFUL_RAW_COMMANDS = ['discard', 'exec', 'multi'];

    /**
     * Whether nothing has made the connection unsafe to share.
     *
     * @var bool
     */
    protected bool $reusable = true;

    /**
     * The number of commands that started but did not return.
     *
     * @var int
     */
    protected int $unfinishedCommands = 0;

    /**
     * Wrap a connection created by the framework connector.
     *
     * @param  \Illuminate\Redis\Connections\PhpRedisConnection  $connection
     * @return static
     */
    public static function fromConnection(PhpRedisConnection $connection): static
    {
        return new static($connection->client, $connection->connector, $connection->config);
    }

    /**
     * Run a command against the Redis database.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function command($method, array $parameters = [])
    {
        $name = strtolower($method);

        if (in_array($name, static::STATEFUL_COMMANDS, true)) {
            $this->reusable = false;
        } elseif ($name === 'rawcommand') {
            $raw = is_string($parameters[0] ?? null) ? strtolower($parameters[0]) : '';

            if (in_array($raw, static::STATEFUL_COMMANDS, true) || in_array($raw, static::STATEFUL_RAW_COMMANDS, true)) {
                $this->reusable = false;
            }
        }

        $this->unfinishedCommands++;

        $result = parent::command($method, $parameters);

        $this->unfinishedCommands--;

        return $result;
    }

    /**
     * Subscribe to a set of given channels with wildcards.
     *
     * @param  array|string  $channels
     * @param  \Closure  $callback
     * @param  string  $method
     * @return void
     */
    public function createSubscription($channels, Closure $callback, $method = 'subscribe')
    {
        $this->reusable = false;

        parent::createSubscription($channels, $callback, $method);
    }

    /**
     * Determine whether the connection can be handed to another request.
     *
     * @return bool
     */
    public function canReturnToPool(): bool
    {
        return $this->reusable && $this->unfinishedCommands === 0;
    }

    /**
     * Get the callable that reconnects this connection.
     *
     * @return callable|null
     */
    public function connector(): ?callable
    {
        return $this->connector;
    }

    /**
     * Get the connection configuration.
     *
     * @return array
     */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * Take the socket away from this lease; later use of the lease throws.
     *
     * @return mixed
     */
    public function detachClient()
    {
        $client = $this->client;

        $this->client = new ReleasedRedisClient;
        $this->connector = null;
        $this->reusable = false;

        return $client;
    }
}
