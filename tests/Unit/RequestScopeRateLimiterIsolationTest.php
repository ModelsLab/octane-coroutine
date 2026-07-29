<?php

namespace Tests\Unit;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\RateLimiter;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Illuminate registers RateLimiter as a singleton that captures a cache
 * Repository at boot. Left alone, every coroutine throttles through that one
 * repository -- and through the single phpredis socket underneath it, which is
 * fatal under Swoole. RequestScope must hand each coroutine its own limiter.
 */
class RequestScopeRateLimiterIsolationTest extends TestCase
{
    public function test_each_coroutine_gets_its_own_rate_limiter(): void
    {
        $base = $this->baseApplication();

        $firstScope = new RequestScope($base);
        $secondScope = new RequestScope($base);

        $first = $firstScope->resolve(RateLimiter::class, new CoroutineApplication($base));
        $second = $secondScope->resolve(RateLimiter::class, new CoroutineApplication($base));

        $this->assertInstanceOf(RateLimiter::class, $first);
        $this->assertNotSame($base->make(RateLimiter::class), $first);
        $this->assertNotSame($first, $second);
    }

    public function test_the_scoped_limiter_uses_the_coroutine_local_cache_repository(): void
    {
        $base = $this->baseApplication();

        $scope = new RequestScope($base);
        $sandbox = new CoroutineApplication($base);

        $limiter = $scope->resolve(RateLimiter::class, $sandbox);

        $scopedRepository = $this->readProperty($limiter, 'cache');
        $baseRepository = $this->readProperty($base->make(RateLimiter::class), 'cache');

        $this->assertNotSame($baseRepository, $scopedRepository);
        $this->assertNotSame($baseRepository->getStore(), $scopedRepository->getStore());

        // It must be the repository from this coroutine's cache manager, not a
        // freshly built one that bypasses the scope.
        $this->assertSame($scope->resolve('cache', $sandbox)->store(), $scopedRepository);
    }

    public function test_named_limiters_registered_at_boot_survive_scoping(): void
    {
        $base = $this->baseApplication();

        $base->make(RateLimiter::class)->for('api', fn () => 'api-limit');

        $limiter = (new RequestScope($base))->resolve(RateLimiter::class, new CoroutineApplication($base));

        $this->assertIsCallable($limiter->limiter('api'));
        $this->assertSame('api-limit', ($limiter->limiter('api'))());
    }

    public function test_the_configured_limiter_store_is_respected(): void
    {
        $base = $this->baseApplication(limiterStore: 'other');

        $scope = new RequestScope($base);
        $sandbox = new CoroutineApplication($base);

        $limiter = $scope->resolve(RateLimiter::class, $sandbox);

        $this->assertSame(
            $scope->resolve('cache', $sandbox)->driver('other'),
            $this->readProperty($limiter, 'cache')
        );
    }

    public function test_resolving_through_the_sandbox_returns_the_scoped_limiter(): void
    {
        $base = $this->baseApplication();

        $scope = new RequestScope($base);
        $sandbox = new CoroutineApplication($base);

        $scoped = $scope->resolve(RateLimiter::class, $sandbox);

        // Repeated resolution inside one request must not rebuild the limiter.
        $this->assertSame($scoped, $scope->resolve(RateLimiter::class, $sandbox));
    }

    private function baseApplication(?string $limiterStore = null): Application
    {
        $app = new Application(__DIR__);

        $app->instance('config', new ConfigRepository([
            'cache' => [
                'default' => 'array',
                'limiter' => $limiterStore,
                'stores' => [
                    'array' => ['driver' => 'array'],
                    'other' => ['driver' => 'array'],
                ],
            ],
        ]));

        $app->singleton('cache', fn ($app) => new CacheManager($app));

        $app->singleton(RateLimiter::class, fn ($app) => new RateLimiter(
            $app->make('cache')->driver($app['config']->get('cache.limiter'))
        ));

        return $app;
    }

    private function readProperty(object $target, string $property)
    {
        $reflection = new ReflectionProperty($target, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($target);
    }
}
