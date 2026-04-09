<?php

namespace Tests\Unit;

use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class RequestScopeSessionMiddlewareIsolationTest extends TestCase
{
    public function test_start_session_middleware_uses_coroutine_scoped_session_and_cache_dependencies(): void
    {
        $base = new Application(__DIR__);

        $config = new ConfigRepository([
            'session' => [
                'driver' => 'array',
                'lottery' => [0, 100],
            ],
            'cache' => [
                'default' => 'array',
                'stores' => [
                    'array' => ['driver' => 'array'],
                ],
            ],
        ]);

        $base->instance('config', $config);
        $base->instance('session', new SessionManager($base));
        $base->instance('cache', new CacheManager($base));

        $scope = new RequestScope($base);
        $sandbox = new CoroutineApplication($base);

        Context::set('octane.request_scope', $scope);

        try {
            $middleware = $sandbox->make(StartSession::class);
            $scopedSessionManager = $sandbox->make('session');
            $scopedCacheManager = $sandbox->make('cache');

            $this->assertInstanceOf(StartSession::class, $middleware);
            $this->assertSame($scopedSessionManager, $this->readProperty($middleware, 'manager'));
            $this->assertNotSame($base->make('session'), $this->readProperty($middleware, 'manager'));

            $cacheFactoryResolver = $this->readProperty($middleware, 'cacheFactoryResolver');

            $this->assertIsCallable($cacheFactoryResolver);
            $this->assertSame($scopedCacheManager, $cacheFactoryResolver());
        } finally {
            Context::clear();
        }
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
