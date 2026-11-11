<?php

namespace Tests\Unit;

use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Laravel\Octane\Listeners\GiveNewRequestInstanceToApplication;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use Laravel\Octane\Swoole\Coroutine\WorkerRequestBinding;
use PHPUnit\Framework\TestCase;
use SessionHandlerInterface;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class WorkerRequestBindingTest extends TestCase
{
    public function test_worker_container_resolves_coroutine_request_with_session(): void
    {
        if (! extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole is required for coroutine context.');
        }

        $base = new Application(__DIR__);
        $base->instance('events', new Dispatcher($base));

        $request = Request::create('/login', 'POST');
        $request->setLaravelSession($this->arraySession());

        $ran = false;

        \Swoole\Coroutine\run(function () use ($base, $request, &$ran): void {
            $scope = new RequestScope($base, $request);
            Context::set('octane.request_scope', $scope);

            $sandbox = new CoroutineApplication($base);
            Context::set('octane.app', $sandbox);

            $listener = new GiveNewRequestInstanceToApplication;
            $listener->handle((object) [
                'app' => $base,
                'sandbox' => $sandbox,
                'request' => $request,
            ]);

            // Simulate Laravel resolving a listener via the worker/base container.
            $resolved = $base->make(Request::class);

            $this->assertSame($request, $resolved);
            $this->assertSame($request, $base->make('request'));
            $this->assertSame($request, $base->make(SymfonyRequest::class));
            $this->assertTrue($resolved->hasSession());

            $listenerInstance = $base->make(WorkerRequestBindingProbeListener::class);
            $this->assertSame($request, $listenerInstance->request);
            $this->assertTrue($listenerInstance->request->hasSession());

            $ran = true;

            Context::clear();
        });

        $this->assertTrue($ran);
    }

    public function test_concurrent_coroutines_do_not_share_worker_request_instances(): void
    {
        if (! extension_loaded('swoole') || ! class_exists(\Swoole\Coroutine\Channel::class)) {
            $this->markTestSkipped('Swoole is required for coroutine context.');
        }

        $base = new Application(__DIR__);
        WorkerRequestBinding::install($base);

        $seen = [];

        \Swoole\Coroutine\run(function () use ($base, &$seen): void {
            $channel = new \Swoole\Coroutine\Channel(2);

            foreach (['/one', '/two'] as $path) {
                \Swoole\Coroutine::create(function () use ($base, $path, $channel): void {
                    $request = Request::create($path, 'GET');
                    $scope = new RequestScope($base, $request);
                    Context::set('octane.request_scope', $scope);

                    $channel->push($base->make(Request::class)->getPathInfo());

                    Context::clear();
                });
            }

            $seen[] = $channel->pop();
            $seen[] = $channel->pop();
        });

        sort($seen);

        $this->assertSame(['/one', '/two'], $seen);
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
}

class WorkerRequestBindingProbeListener
{
    public function __construct(public Request $request) {}
}
