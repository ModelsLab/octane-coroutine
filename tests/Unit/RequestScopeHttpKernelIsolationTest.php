<?php

namespace Tests\Unit;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Routing\Router;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class RequestScopeHttpKernelIsolationTest extends TestCase
{
    public function test_http_kernel_uses_coroutine_scoped_router(): void
    {
        $base = new Application(__DIR__);
        $events = $this->createMock(Dispatcher::class);
        $router = new Router($events, $base);
        $kernel = new Kernel($base, $router);

        $base->instance('events', $events);
        $base->instance('router', $router);
        $base->instance(HttpKernelContract::class, $kernel);

        $scope = new RequestScope($base);
        $sandbox = new CoroutineApplication($base);

        Context::set('octane.request_scope', $scope);

        try {
            /** @var \Illuminate\Foundation\Http\Kernel $scopedKernel */
            $scopedKernel = $sandbox->make(HttpKernelContract::class);
            $scopedRouter = $sandbox->make('router');

            $this->assertNotSame($kernel, $scopedKernel);
            $this->assertSame($sandbox, $this->readProperty($scopedKernel, 'app'));
            $this->assertSame($scopedRouter, $this->readProperty($scopedKernel, 'router'));
            $this->assertNotSame($router, $scopedRouter);
            $this->assertSame($sandbox, $this->readProperty($scopedRouter, 'container'));
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
