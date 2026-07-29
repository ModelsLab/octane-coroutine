<?php

namespace {
    require_once __DIR__.'/../Fixtures/sentry_stubs.php';
}

namespace Tests\Unit {
    use Illuminate\Foundation\Application;
    use Laravel\Octane\Swoole\Coroutine\Context;
    use Laravel\Octane\Swoole\Coroutine\RequestScope;
    use Laravel\Octane\Swoole\Coroutine\SentryHubProxy;
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;
    use Sentry\Breadcrumb;
    use Sentry\Laravel\Tracing\Middleware;
    use Sentry\SentrySdk;
    use Sentry\State\Hub;
    use Sentry\State\HubInterface;

    class RequestScopeSentryIsolationTest extends TestCase
    {
        protected function tearDown(): void
        {
            Context::clear();
            SentrySdk::setCurrentHub(new Hub);

            parent::tearDown();
        }

        public function test_sentry_sdk_static_hub_delegates_to_a_fresh_coroutine_hub_per_scope(): void
        {
            $baseHub = new Hub;
            $base = new Application(__DIR__);
            $base->instance('sentry', $baseHub);
            SentrySdk::setCurrentHub($baseHub);

            new RequestScope($base);

            $proxy = SentrySdk::getCurrentHub();
            $firstHub = Context::get(SentryHubProxy::CONTEXT_KEY);

            $this->assertInstanceOf(SentryHubProxy::class, $proxy);
            $this->assertInstanceOf(HubInterface::class, $firstHub);
            $this->assertNotSame($baseHub, $firstHub);

            $proxy->addBreadcrumb(new Breadcrumb('first-request'));

            $this->assertCount(0, $baseHub->breadcrumbs);
            $this->assertCount(1, $firstHub->breadcrumbs);

            new RequestScope($base);

            $secondHub = Context::get(SentryHubProxy::CONTEXT_KEY);

            $this->assertNotSame($firstHub, $secondHub);
            $this->assertCount(0, $secondHub->breadcrumbs);

            $proxy->addBreadcrumb(new Breadcrumb('second-request'));

            $this->assertCount(1, $firstHub->breadcrumbs);
            $this->assertCount(1, $secondHub->breadcrumbs);
            $this->assertCount(0, $baseHub->breadcrumbs);
        }

        public function test_sentry_tracing_middleware_is_request_scoped_and_reset(): void
        {
            $base = new Application(__DIR__);
            $sandbox = new Application(__DIR__);
            $baseMiddleware = new Middleware(false);
            $baseMiddleware->markDirty();
            $base->instance(Middleware::class, $baseMiddleware);
            $scope = new RequestScope($base);

            $middleware = $scope->resolve(Middleware::class, $sandbox);

            $this->assertInstanceOf(Middleware::class, $middleware);
            $this->assertNotSame($baseMiddleware, $middleware);
            $this->assertFalse($this->readProperty($middleware, 'continueAfterResponse'));
            $this->assertNull($this->readProperty($middleware, 'transaction'));
            $this->assertNull($this->readProperty($middleware, 'appSpan'));
            $this->assertNull($this->readProperty($middleware, 'bootedTimestamp'));
            $this->assertFalse($this->readProperty($middleware, 'didRouteMatch'));
            $this->assertSame($middleware, $scope->resolve(Middleware::class, $sandbox));
        }

        private function readProperty(object $object, string $property): mixed
        {
            $reflection = new ReflectionProperty($object, $property);
            $reflection->setAccessible(true);

            return $reflection->getValue($object);
        }
    }
}
