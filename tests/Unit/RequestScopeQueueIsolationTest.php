<?php

namespace Tests\Unit;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Queue\FailoverQueue;
use Illuminate\Queue\Connectors\RedisConnector;
use Illuminate\Queue\NullQueue;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\SyncQueue;
use Illuminate\Redis\RedisManager;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

class RequestScopeQueueIsolationTest extends TestCase
{
    public function test_redis_queue_manager_is_request_scoped_and_uses_request_scoped_redis(): void
    {
        $base = $this->applicationWithRedisQueue();
        $baseRedis = $base->make('redis');
        $baseQueue = $base->make('queue');
        $baseConnection = $baseQueue->connection('redis');

        $scope = new RequestScope($base);
        $sandbox = new CoroutineApplication($base);

        $scopedQueue = $scope->resolve('queue', $sandbox);

        $this->assertInstanceOf(QueueManager::class, $scopedQueue);
        $this->assertNotSame($baseQueue, $scopedQueue);
        $this->assertSame($sandbox, $this->readProperty($scopedQueue, 'app'));
        $this->assertSame([], $this->readProperty($scopedQueue, 'connections'));

        $scopedConnection = $scopedQueue->connection('redis');

        $this->assertInstanceOf(RedisQueue::class, $scopedConnection);
        $this->assertNotSame($baseConnection, $scopedConnection);
        $this->assertSame($sandbox, $scopedConnection->getContainer());
        $this->assertSame($base, $baseConnection->getContainer());
        $this->assertNotSame($baseRedis, $scopedConnection->getRedis());
        $this->assertSame($scope->resolve('redis', $sandbox), $scopedConnection->getRedis());
    }

    public function test_clear_releases_request_scoped_queue_connections(): void
    {
        $base = $this->applicationWithRedisQueue();
        $queue = $base->make('queue');

        $queue->connection('redis');

        $this->assertArrayHasKey('redis', $this->readProperty($queue, 'connections'));

        $scope = new RequestScope($base);
        $scope->set('queue', $queue);

        $scope->clear();

        $this->assertSame([], $this->readProperty($queue, 'connections'));
    }

    public function test_standard_queue_drivers_resolve_with_the_sandbox_container(): void
    {
        $base = $this->applicationWithRedisQueue();
        $scope = new RequestScope($base);
        $sandbox = new CoroutineApplication($base);

        $scopedQueue = $scope->resolve('queue', $sandbox);
        $defaultConnection = $scope->resolve('queue.connection', $sandbox);
        $sync = $scopedQueue->connection('sync');
        $null = $scopedQueue->connection('null');
        $failover = $scopedQueue->connection('failover');

        $this->assertInstanceOf(RedisQueue::class, $defaultConnection);
        $this->assertSame($scopedQueue->connection('redis'), $defaultConnection);
        $this->assertInstanceOf(SyncQueue::class, $sync);
        $this->assertInstanceOf(NullQueue::class, $null);
        $this->assertInstanceOf(FailoverQueue::class, $failover);
        $this->assertSame($sandbox, $sync->getContainer());
        $this->assertSame($sandbox, $null->getContainer());
        $this->assertSame($sandbox, $failover->getContainer());
        $this->assertSame($scopedQueue, $failover->manager);
        $this->assertSame(['null', 'sync'], $failover->connections);
    }

    public function test_coroutine_application_resolves_distinct_redis_queues_per_coroutine(): void
    {
        if (! class_exists(Coroutine::class) || ! class_exists(Channel::class)) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $base = $this->applicationWithRedisQueue();
        $sandbox = new CoroutineApplication($base);
        $results = [];

        \Swoole\Coroutine\run(function () use ($base, $sandbox, &$results) {
            $done = new Channel(2);

            for ($i = 0; $i < 2; $i++) {
                Coroutine::create(function () use ($base, $sandbox, $done) {
                    $scope = new RequestScope($base);

                    Context::set('octane.request_scope', $scope);

                    $queue = $sandbox->make('queue');
                    $connection = $queue->connection('redis');

                    $done->push([
                        'queue' => spl_object_id($queue),
                        'connection' => spl_object_id($connection),
                        'redis' => spl_object_id($connection->getRedis()),
                    ]);

                    $scope->clear();
                    Context::clear();
                });
            }

            for ($i = 0; $i < 2; $i++) {
                $results[] = $done->pop();
            }
        });

        $this->assertCount(2, $results);
        $this->assertNotSame($results[0]['queue'], $results[1]['queue']);
        $this->assertNotSame($results[0]['connection'], $results[1]['connection']);
        $this->assertNotSame($results[0]['redis'], $results[1]['redis']);
    }

    private function applicationWithRedisQueue(): Application
    {
        $app = new Application(__DIR__);

        $config = new ConfigRepository([
            'database' => [
                'redis' => [
                    'client' => 'phpredis',
                    'options' => [],
                    'default' => [
                        'host' => '127.0.0.1',
                        'port' => 6379,
                        'persistent' => true,
                        'persistent_id' => 'worker-queue',
                    ],
                ],
            ],
            'queue' => [
                'default' => 'redis',
                'connections' => [
                    'sync' => [
                        'driver' => 'sync',
                        'after_commit' => false,
                    ],
                    'null' => [
                        'driver' => 'null',
                    ],
                    'redis' => [
                        'driver' => 'redis',
                        'connection' => 'default',
                        'queue' => 'default',
                        'retry_after' => 90,
                        'block_for' => null,
                        'after_commit' => false,
                    ],
                    'failover' => [
                        'driver' => 'failover',
                        'connections' => ['null', 'sync'],
                    ],
                ],
            ],
        ]);

        $app->instance('config', $config);
        $app->instance('events', new Dispatcher($app));
        $app->instance('redis', new RedisManager($app, 'phpredis', $config->get('database.redis')));

        $queue = new QueueManager($app);
        $queue->addConnector('redis', static fn () => new RedisConnector($app->make('redis')));
        $app->instance('queue', $queue);

        return $app;
    }

    private function readProperty(object $object, string $property)
    {
        $reflection = new ReflectionClass($object);

        while (! $reflection->hasProperty($property) && $reflection->getParentClass()) {
            $reflection = $reflection->getParentClass();
        }

        $instanceProperty = $reflection->getProperty($property);
        $instanceProperty->setAccessible(true);

        return $instanceProperty->getValue($object);
    }
}
