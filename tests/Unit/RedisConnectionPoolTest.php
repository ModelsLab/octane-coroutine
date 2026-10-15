<?php

namespace Tests\Unit;

use Closure;
use LogicException;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Redis\Connections\Connection;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Redis\CoroutineRedisManager;
use Laravel\Octane\Swoole\Redis\PooledPhpRedisConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * Every request coroutine used to open its own Redis connections (connect, AUTH, SELECT)
 * and close them at the end; under load the closed sockets filled TIME_WAIT until connects
 * failed with "Cannot assign requested address". With the pool enabled a clean connection
 * goes back to the worker and the next request reuses it; anything that could carry state
 * into another request is closed instead.
 */
class RedisConnectionPoolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('redis') || ! extension_loaded('swoole')) {
            $this->markTestSkipped('phpredis and Swoole are required.');
        }

        $socket = @fsockopen('127.0.0.1', 6379, $errno, $errstr, 0.5);

        if ($socket === false) {
            $this->markTestSkipped('A Redis server on 127.0.0.1:6379 is required.');
        }

        fclose($socket);

        CoroutineRedisManager::flushPool();
        Context::clear();
    }

    protected function tearDown(): void
    {
        CoroutineRedisManager::flushPool();
        Context::clear();

        parent::tearDown();
    }

    public function test_sequential_requests_reuse_one_connection(): void
    {
        $manager = $this->manager();

        $first = $this->request($manager, fn (Connection $redis) => $redis->set('pool:k', 'v') ? $this->clientId($redis) : null);
        $second = $this->request($manager, fn (Connection $redis) => [$this->clientId($redis), $redis->get('pool:k')]);

        $this->assertSame($first, $second[0]);
        $this->assertSame('v', $second[1]);
    }

    public function test_concurrent_requests_never_share_a_connection(): void
    {
        $manager = $this->manager();
        $ids = [];

        Coroutine\run(function () use ($manager, &$ids) {
            $bothHolding = new Channel(2);
            $release = new Channel(2);
            $done = new Channel(2);

            for ($i = 0; $i < 2; $i++) {
                Coroutine::create(function () use ($manager, $bothHolding, $release, $done) {
                    $id = $this->clientId($manager->connection());
                    $bothHolding->push(true);
                    $release->pop();
                    $manager->releaseConnections();
                    $done->push($id);
                });
            }

            $bothHolding->pop();
            $bothHolding->pop();
            $release->push(true);
            $release->push(true);
            $ids = [$done->pop(), $done->pop()];
        });

        $this->assertNotSame($ids[0], $ids[1]);
    }

    #[DataProvider('dirtyConnections')]
    public function test_a_connection_that_could_carry_state_is_closed_not_reused(Closure $dirty): void
    {
        $manager = $this->manager();

        $first = $this->request($manager, function (Connection $redis) use ($dirty) {
            $id = $this->clientId($redis);

            try {
                $dirty($redis);
            } catch (RedisException) {
                // A failed command is one of the cases under test.
            }

            return $id;
        });

        $second = $this->request($manager, fn (Connection $redis) => [$this->clientId($redis), $redis->get('pool:clean')]);

        $this->assertNotSame($first, $second[0]);
        $this->assertFalse($this->clientIsOpen($first));
        $this->assertNull($second[1]);
    }

    public static function dirtyConnections(): array
    {
        return [
            'MULTI left open' => [fn (Connection $redis) => $redis->multi()->set('pool:clean', 'leaked')],
            'pipeline left open' => [fn (Connection $redis) => $redis->client()->pipeline()->set('pool:clean', 'leaked')],
            'SELECT of another database' => [fn (Connection $redis) => $redis->select(14)],
            'a changed key prefix' => [fn (Connection $redis) => $redis->client()->setOption(Redis::OPT_PREFIX, 'other:')],
            'WATCH' => [fn (Connection $redis) => $redis->watch('pool:clean')],
            'raw SELECT' => [fn (Connection $redis) => $redis->executeRaw(['SELECT', '14'])],
            'raw MULTI' => [fn (Connection $redis) => $redis->executeRaw(['MULTI'])],
        ];
    }

    public function test_a_command_cut_off_before_its_reply_is_not_reused(): void
    {
        $manager = $this->manager([], ['read_timeout' => 0.2]);

        $first = $this->request($manager, function (Connection $redis) {
            $id = $this->clientId($redis);

            try {
                $redis->blpop(['pool:empty'], 2);
            } catch (RedisException) {
                // The read timed out with the reply still pending.
            }

            return $id;
        });

        $second = $this->request($manager, fn (Connection $redis) => [$this->clientId($redis), $redis->set('pool:after-timeout', '1')]);

        $this->assertNotSame($first, $second[0]);
        $this->assertTrue($second[1]);
    }

    public function test_a_connection_kept_after_its_request_cannot_reach_the_pooled_socket(): void
    {
        $manager = $this->manager();

        [$kept, $id] = $this->request($manager, fn (Connection $redis) => [$redis, $this->clientId($redis)]);
        $next = $this->request($manager, fn (Connection $redis) => [$this->clientId($redis), $redis->get('pool:k2')]);

        $this->assertSame($id, $next[0]);

        $this->expectException(LogicException::class);
        $kept->get('pool:k2');
    }

    public function test_idle_sockets_below_the_top_of_the_stack_expire(): void
    {
        $manager = $this->manager(['max_idle_time' => 0.3]);
        $ids = $this->twoConcurrentRequests($manager);

        // Refresh only the socket on top of the stack.
        usleep(200_000);
        $top = $this->request($manager, fn (Connection $redis) => $this->clientId($redis));
        $bottom = $top === $ids[0] ? $ids[1] : $ids[0];

        // The bottom socket is now past max_idle_time; the next return prunes it.
        usleep(150_000);
        $this->request($manager, fn (Connection $redis) => $this->clientId($redis));

        $this->assertFalse($this->clientIsOpen($bottom));
        $this->assertTrue($this->clientIsOpen($top));
    }

    public function test_idle_connections_above_the_cap_are_closed(): void
    {
        $manager = $this->manager(['max_idle' => 1]);
        $ids = [];

        Coroutine\run(function () use ($manager, &$ids) {
            $bothHolding = new Channel(2);
            $release = new Channel(2);
            $done = new Channel(2);

            for ($i = 0; $i < 2; $i++) {
                Coroutine::create(function () use ($manager, $bothHolding, $release, $done) {
                    $id = $this->clientId($manager->connection());
                    $bothHolding->push(true);
                    $release->pop();
                    $manager->releaseConnections();
                    $done->push($id);
                });
            }

            $bothHolding->pop();
            $bothHolding->pop();
            $release->push(true);
            $release->push(true);
            $ids = [$done->pop(), $done->pop()];
        });

        $open = array_filter($ids, fn (int $id) => $this->clientIsOpen($id));

        $this->assertCount(1, $open);
    }

    public function test_a_connection_idle_too_long_is_replaced(): void
    {
        $manager = $this->manager(['max_idle_time' => 0.05]);

        $first = $this->request($manager, fn (Connection $redis) => $this->clientId($redis));
        usleep(100_000);
        $second = $this->request($manager, fn (Connection $redis) => $this->clientId($redis));

        $this->assertNotSame($first, $second);
        $this->assertFalse($this->clientIsOpen($first));
    }

    public function test_a_connection_the_server_dropped_is_replaced_before_use(): void
    {
        $manager = $this->manager(['ping_after_idle' => 0]);

        $first = $this->request($manager, fn (Connection $redis) => $this->clientId($redis));
        $admin = $this->admin();
        $admin->rawCommand('CLIENT', 'KILL', 'ID', (string) $first);
        $admin->close();

        // phpredis reconnects (with AUTH and SELECT) when it finds the socket closed; the
        // ping guards against half-open sockets, where no close ever arrives.
        $second = $this->request($manager, fn (Connection $redis) => [$this->clientId($redis), $redis->set('pool:after-kill', '1')]);

        $this->assertNotSame($first, $second[0]);
        $this->assertTrue($second[1]);
    }

    public function test_with_the_pool_disabled_every_request_closes_its_connection(): void
    {
        $manager = $this->manager(['enabled' => false]);

        $first = $this->request($manager, fn (Connection $redis) => [$this->clientId($redis), $redis]);
        $second = $this->request($manager, fn (Connection $redis) => $this->clientId($redis));

        $this->assertNotInstanceOf(PooledPhpRedisConnection::class, $first[1]);
        $this->assertNotSame($first[0], $second);
        $this->assertFalse($this->clientIsOpen($first[0]));
    }

    public function test_outside_a_coroutine_the_framework_connection_is_used(): void
    {
        $manager = $this->manager();

        $connection = $manager->connection();

        $this->assertSame($connection, $manager->connection());
        $this->assertNotInstanceOf(PooledPhpRedisConnection::class, $connection);
    }

    /**
     * Two requests that hold a connection at the same time, then release it.
     *
     * @return array{0: int, 1: int}
     */
    private function twoConcurrentRequests(CoroutineRedisManager $manager): array
    {
        $ids = [];

        Coroutine\run(function () use ($manager, &$ids) {
            $bothHolding = new Channel(2);
            $release = new Channel(2);
            $done = new Channel(2);

            for ($i = 0; $i < 2; $i++) {
                Coroutine::create(function () use ($manager, $bothHolding, $release, $done) {
                    $id = $this->clientId($manager->connection());
                    $bothHolding->push(true);
                    $release->pop();
                    $manager->releaseConnections();
                    $done->push($id);
                });
            }

            $bothHolding->pop();
            $bothHolding->pop();
            $release->push(true);
            $release->push(true);
            $ids = [$done->pop(), $done->pop()];
        });

        return $ids;
    }

    /**
     * Run one "request": a coroutine that uses the default connection and releases it.
     */
    private function request(CoroutineRedisManager $manager, Closure $callback)
    {
        $result = null;

        Coroutine\run(function () use ($manager, $callback, &$result) {
            $result = $callback($manager->connection());
            $manager->releaseConnections();
        });

        return $result;
    }

    private function manager(array $pool = [], array $connection = []): CoroutineRedisManager
    {
        $redis = [
            'default' => array_merge(['host' => '127.0.0.1', 'port' => 6379, 'database' => 15], $connection),
        ];

        $app = new Application(__DIR__);
        $app->instance('config', new ConfigRepository([
            'database' => ['redis' => $redis],
            'octane' => ['swoole' => ['redis_pool' => array_merge(['enabled' => true], $pool)]],
        ]));

        CoroutineRedisManager::flushPool();

        return new CoroutineRedisManager($app, 'phpredis', $redis);
    }

    private function clientId(Connection $redis): int
    {
        return (int) $redis->client()->rawCommand('CLIENT', 'ID');
    }

    private function clientIsOpen(int $id): bool
    {
        // The server drops a closed client asynchronously; give it a moment.
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $admin = $this->admin();
            $open = str_contains((string) $admin->rawCommand('CLIENT', 'LIST'), 'id='.$id.' ');
            $admin->close();

            if (! $open) {
                return false;
            }

            usleep(25_000);
        }

        return true;
    }

    private function admin(): Redis
    {
        $redis = new Redis;
        $redis->connect('127.0.0.1', 6379);

        return $redis;
    }
}
