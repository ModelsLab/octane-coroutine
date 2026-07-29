<?php

namespace Tests\Unit;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Redis\Connections\Connection;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Redis\CoroutineRedisManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class CoroutineRedisManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('redis')) {
            $this->markTestSkipped('phpredis is required for the coroutine Redis manager tests.');
        }

        Context::clear();
    }

    protected function tearDown(): void
    {
        Context::clear();

        parent::tearDown();
    }

    public function test_persistent_connections_are_disabled_for_coroutine_connections(): void
    {
        $manager = $this->manager();

        $config = $this->callProtected($manager, 'coroutineSafeConfig');

        $this->assertFalse($config['default']['persistent']);
        $this->assertArrayNotHasKey('persistent_id', $config['default']);
        $this->assertFalse($config['cache']['persistent']);
        $this->assertArrayNotHasKey('persistent_id', $config['cache']);
    }

    public function test_options_and_clusters_keys_are_left_alone(): void
    {
        $manager = $this->manager();

        $config = $this->callProtected($manager, 'coroutineSafeConfig');

        $this->assertSame(['prefix' => 'octane:'], $config['options']);
        $this->assertSame(
            [['host' => '127.0.0.1', 'port' => 6379]],
            $config['clusters']['big']
        );
    }

    public function test_connection_names_are_normalized(): void
    {
        $manager = $this->manager();

        $this->assertSame('default', $this->callProtected($manager, 'normalizeConnectionName', null));
        $this->assertSame('default', $this->callProtected($manager, 'normalizeConnectionName', ''));
        $this->assertSame('cache', $this->callProtected($manager, 'normalizeConnectionName', 'cache'));
    }

    public function test_outside_a_coroutine_it_behaves_like_the_framework_manager(): void
    {
        if (! $this->redisIsReachable()) {
            $this->markTestSkipped('A Redis server on 127.0.0.1:6379 is required.');
        }

        $manager = $this->manager();

        $first = $manager->connection();
        $second = $manager->connection();

        // The framework caches connections on the manager itself.
        $this->assertSame($first, $second);
        $this->assertArrayHasKey('default', $manager->connections());
        $this->assertNull(Context::get(CoroutineRedisManager::CONTEXT_NAMES));
    }

    public function test_each_coroutine_gets_its_own_connection(): void
    {
        if (! extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole is required to exercise per-coroutine connections.');
        }

        if (! $this->redisIsReachable()) {
            $this->markTestSkipped('A Redis server on 127.0.0.1:6379 is required.');
        }

        $manager = $this->manager();
        $sockets = [];
        $stableWithinCoroutine = [];

        \Swoole\Coroutine\run(function () use ($manager, &$sockets, &$stableWithinCoroutine) {
            $done = new \Swoole\Coroutine\Channel(2);

            foreach (['a', 'b'] as $label) {
                \Swoole\Coroutine::create(function () use ($manager, $label, $done, &$sockets, &$stableWithinCoroutine) {
                    $connection = $manager->connection();

                    // Give the sibling coroutine a chance to interleave.
                    \Swoole\Coroutine::sleep(0.05);

                    $stableWithinCoroutine[$label] = $manager->connection() === $connection;
                    $sockets[$label] = spl_object_id($connection->client());

                    $manager->releaseConnections();

                    $done->push(true);
                });
            }

            $done->pop();
            $done->pop();
        });

        $this->assertCount(2, $sockets);
        $this->assertNotSame(
            $sockets['a'],
            $sockets['b'],
            'Concurrent coroutines must not share a phpredis socket.'
        );
        $this->assertSame([true, true], array_values($stableWithinCoroutine));
    }

    public function test_release_connections_clears_the_coroutine_context(): void
    {
        if (! extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole is required to exercise per-coroutine connections.');
        }

        if (! $this->redisIsReachable()) {
            $this->markTestSkipped('A Redis server on 127.0.0.1:6379 is required.');
        }

        $manager = $this->manager();
        $observed = [];

        \Swoole\Coroutine\run(function () use ($manager, &$observed) {
            $manager->connection();
            $manager->connection('cache');

            $observed['namesBefore'] = array_keys(Context::get(CoroutineRedisManager::CONTEXT_NAMES, []));
            $observed['connectionBefore'] = Context::get(CoroutineRedisManager::CONTEXT_PREFIX.'default') instanceof Connection;

            $observed['released'] = $manager->releaseConnections();

            $observed['namesAfter'] = Context::get(CoroutineRedisManager::CONTEXT_NAMES);
            $observed['connectionAfter'] = Context::get(CoroutineRedisManager::CONTEXT_PREFIX.'default');
        });

        $this->assertSame(['default', 'cache'], $observed['namesBefore']);
        $this->assertTrue($observed['connectionBefore']);
        $this->assertSame(2, $observed['released']);
        $this->assertNull($observed['namesAfter']);
        $this->assertNull($observed['connectionAfter']);
    }

    public function test_releasing_outside_a_coroutine_is_a_no_op(): void
    {
        $this->assertSame(0, $this->manager()->releaseConnections());
    }

    private function manager(): CoroutineRedisManager
    {
        $app = new Application(__DIR__);

        $config = [
            'client' => 'phpredis',
            'options' => ['prefix' => 'octane:'],
            'default' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'database' => 15,
                'persistent' => true,
                'persistent_id' => 'worker-default',
            ],
            'cache' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'database' => 15,
                'persistent' => true,
                'persistent_id' => 'worker-cache',
            ],
            'clusters' => [
                'big' => [['host' => '127.0.0.1', 'port' => 6379]],
            ],
        ];

        $app->instance('config', new ConfigRepository(['database' => ['redis' => $config]]));

        return new CoroutineRedisManager($app, 'phpredis', $config);
    }

    private function callProtected(object $target, string $method, ...$arguments)
    {
        $reflection = (new ReflectionClass($target))->getMethod($method);
        $reflection->setAccessible(true);

        return $reflection->invoke($target, ...$arguments);
    }

    private function redisIsReachable(): bool
    {
        $socket = @fsockopen('127.0.0.1', 6379, $errno, $errstr, 0.5);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
