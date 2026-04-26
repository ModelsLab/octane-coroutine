<?php

namespace Tests\Unit;

use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Redis\RedisManager;
use Illuminate\Session\SessionManager;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class RequestScopeRedisIsolationTest extends TestCase
{
    public function test_request_scoped_redis_and_cache_managers_do_not_reuse_worker_level_state(): void
    {
        $base = new Application(__DIR__);

        $config = new ConfigRepository([
            'database' => [
                'redis' => [
                    'client' => 'phpredis',
                    'options' => [],
                    'default' => [
                        'host' => '127.0.0.1',
                        'port' => 6379,
                        'persistent' => true,
                        'persistent_id' => 'worker-default',
                    ],
                    'session' => [
                        'host' => '127.0.0.1',
                        'port' => 6379,
                        'persistent' => true,
                        'persistent_id' => 'worker-session',
                    ],
                ],
            ],
            'cache' => [
                'default' => 'array',
                'stores' => [
                    'array' => ['driver' => 'array'],
                    'redis' => ['driver' => 'redis', 'connection' => 'default'],
                ],
            ],
        ]);

        $base->instance('config', $config);
        $base->instance('redis', new RedisManager($base, 'phpredis', $config->get('database.redis')));

        $cache = new CacheManager($base);
        $cache->store('array');
        $base->instance('cache', $cache);

        $scope = new RequestScope($base);
        $sandbox = new CoroutineApplication($base);

        $scopedRedis = $scope->resolve('redis', $sandbox);
        $scopedCache = $scope->resolve('cache', $sandbox);

        $this->assertNotSame($base->make('redis'), $scopedRedis);
        $this->assertNotSame($base->make('cache'), $scopedCache);

        $this->assertSame($sandbox, $this->readProperty($scopedRedis, 'app'));
        $this->assertSame([], $this->readProperty($scopedRedis, 'connections'));
        $this->assertSame($sandbox, $this->readProperty($scopedCache, 'app'));
        $this->assertSame([], $this->readProperty($scopedCache, 'stores'));

        $scopedRedisConfig = $this->readProperty($scopedRedis, 'config');

        $this->assertFalse($scopedRedisConfig['default']['persistent']);
        $this->assertFalse($scopedRedisConfig['session']['persistent']);
        $this->assertArrayNotHasKey('persistent_id', $scopedRedisConfig['default']);
        $this->assertArrayNotHasKey('persistent_id', $scopedRedisConfig['session']);

        $baseRedisConfig = $this->readProperty($base->make('redis'), 'config');

        $this->assertTrue($baseRedisConfig['default']['persistent']);
        $this->assertSame('worker-default', $baseRedisConfig['default']['persistent_id']);
        $this->assertTrue($baseRedisConfig['session']['persistent']);
        $this->assertSame('worker-session', $baseRedisConfig['session']['persistent_id']);
    }

    public function test_clear_purges_scoped_redis_connections(): void
    {
        $base = new Application(__DIR__);

        $config = new ConfigRepository([
            'database' => [
                'redis' => [
                    'client' => 'phpredis',
                    'options' => [],
                    'default' => ['host' => '127.0.0.1'],
                    'cache' => ['host' => '127.0.0.1'],
                    'session' => ['host' => '127.0.0.1'],
                ],
            ],
            'cache' => [
                'default' => 'redis',
                'stores' => [
                    'array' => ['driver' => 'array'],
                    'redis' => ['driver' => 'redis', 'connection' => 'default'],
                ],
            ],
        ]);

        $base->instance('config', $config);
        $redis = new TrackingRedisManager($base, 'phpredis', $config->get('database.redis'));

        $scope = new RequestScope($base);
        $scope->set('redis', $redis);

        $scope->clear();

        $this->assertSame(['default', 'cache', 'session'], $redis->purged);
    }

    public function test_clear_forgets_scoped_cache_and_session_drivers(): void
    {
        $base = new Application(__DIR__);

        $base->instance('config', new ConfigRepository([
            'database' => [
                'redis' => [
                    'client' => 'phpredis',
                    'options' => [],
                    'default' => ['host' => '127.0.0.1'],
                ],
            ],
            'cache' => [
                'default' => 'redis',
                'stores' => [
                    'array' => ['driver' => 'array'],
                    'redis' => ['driver' => 'redis', 'connection' => 'default'],
                ],
            ],
        ]));

        $cache = new TrackingCacheManager($base);
        $session = new TrackingSessionManager($base);

        $scope = new RequestScope($base);
        $scope->set('cache', $cache);
        $scope->set('session', $session);

        $scope->clear();

        sort($cache->forgotten);

        $this->assertSame(['array', 'redis'], $cache->forgotten);
        $this->assertTrue($session->forgotDrivers);
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

class TrackingRedisManager extends RedisManager
{
    /** @var array<int, string> */
    public array $purged = [];

    public function purge($name = null)
    {
        $this->purged[] = $name ?? 'default';
    }
}

class TrackingCacheManager extends CacheManager
{
    /** @var array<int, string> */
    public array $forgotten = [];

    public function forgetDriver($name = null)
    {
        $this->forgotten[] = $name ?? $this->getDefaultDriver();

        return $this;
    }
}

class TrackingSessionManager extends SessionManager
{
    public bool $forgotDrivers = false;

    public function forgetDrivers()
    {
        $this->forgotDrivers = true;
    }
}
