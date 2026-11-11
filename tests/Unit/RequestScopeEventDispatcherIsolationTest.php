<?php

namespace Tests\Unit;

use Illuminate\Contracts\Events\Dispatcher as EventDispatcherContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SessionHandlerInterface;

class RequestScopeEventDispatcherIsolationTest extends TestCase
{
    public function test_event_dispatcher_resolves_listeners_with_sandbox_request(): void
    {
        $base = new Application(__DIR__);
        $base->instance('events', new Dispatcher($base));

        $request = Request::create('/login', 'POST');
        $request->setLaravelSession($this->arraySession());

        $scope = new RequestScope($base, $request);
        $sandbox = new CoroutineApplication($base);

        Context::set('octane.request_scope', $scope);

        try {
            $events = $sandbox->make('events');

            $this->assertInstanceOf(Dispatcher::class, $events);
            $this->assertNotSame($base->make('events'), $events);
            $this->assertSame($sandbox, $this->readProperty($events, 'container'));

            $resolved = $sandbox->make(Request::class);
            $this->assertSame($request, $resolved);
            $this->assertTrue($resolved->hasSession());
            $this->assertSame($request->session(), $resolved->session());

            $this->assertSame($events, $sandbox->make(EventDispatcherContract::class));
        } finally {
            Context::clear();
        }
    }

    private function arraySession(): Store
    {
        $handler = new class implements SessionHandlerInterface
        {
            private array $data = [];

            public function open(string $path, string $name): bool
            {
                return true;
            }

            public function close(): bool
            {
                return true;
            }

            public function read(string $id): string|false
            {
                return $this->data[$id] ?? '';
            }

            public function write(string $id, string $data): bool
            {
                $this->data[$id] = $data;

                return true;
            }

            public function destroy(string $id): bool
            {
                unset($this->data[$id]);

                return true;
            }

            public function gc(int $max_lifetime): int|false
            {
                return 0;
            }
        };

        return new Store('test', $handler);
    }

    private function readProperty(object $object, string $property): mixed
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
