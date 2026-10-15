<?php

namespace Tests\Unit;

use Illuminate\Bus\Dispatcher;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * terminating() used to forward to the base application, so a callback registered during a
 * request ran at the end of every later request and was never removed. The Blade engine,
 * which Octane resolves again on every request, registers one each time: a worker kept and
 * ran one more callback per request it had served, and slowed down as it aged.
 */
class CoroutineTerminatingTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::clear();

        parent::tearDown();
    }

    public function test_a_request_runs_boot_callbacks_and_its_own_once_and_leaves_the_base_untouched(): void
    {
        [$base, $sandbox] = $this->applications();
        $ran = [];
        $base->terminating(function () use (&$ran) {
            $ran[] = 'boot';
        });

        Context::set('octane.request_scope', new RequestScope($base, Request::create('/one')));
        $sandbox->terminating(function () use (&$ran) {
            $ran[] = 'one';
        });
        $sandbox->terminate();

        $this->assertSame(['boot', 'one'], $ran);
        $this->assertCount(1, $this->baseTerminatingCallbacks($base));
    }

    public function test_a_later_request_does_not_run_an_earlier_requests_callbacks(): void
    {
        [$base, $sandbox] = $this->applications();
        $ran = [];

        Context::set('octane.request_scope', $first = new RequestScope($base, Request::create('/first')));
        $sandbox->terminating(function () use (&$ran) {
            $ran[] = 'first';
        });
        $sandbox->terminate();
        $first->clear();

        Context::set('octane.request_scope', new RequestScope($base, Request::create('/second')));
        $sandbox->terminate();

        $this->assertSame(['first'], $ran);
    }

    public function test_a_callback_registered_while_terminating_runs_in_the_same_pass(): void
    {
        [$base, $sandbox] = $this->applications();
        $ran = [];

        Context::set('octane.request_scope', new RequestScope($base, Request::create('/nested')));
        $sandbox->terminating(function () use ($sandbox, &$ran) {
            $ran[] = 'outer';
            $sandbox->terminating(function () use (&$ran) {
                $ran[] = 'inner';
            });
        });
        $sandbox->terminate();

        $this->assertSame(['outer', 'inner'], $ran);
    }

    public function test_concurrent_requests_only_run_their_own_callbacks(): void
    {
        if (! class_exists(Coroutine::class) || ! class_exists(Channel::class)) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        [$base, $sandbox] = $this->applications();
        $ran = ['a' => [], 'b' => []];

        Coroutine\run(function () use ($base, $sandbox, &$ran) {
            $aRegistered = new Channel(1);
            $bTerminated = new Channel(1);
            $done = new Channel(2);

            Coroutine::create(function () use ($base, $sandbox, &$ran, $aRegistered, $bTerminated, $done) {
                Context::set('octane.request_scope', new RequestScope($base, Request::create('/a')));
                $sandbox->terminating(function () use (&$ran) {
                    $ran['a'][] = 'a';
                });
                $aRegistered->push(true);
                $bTerminated->pop();
                $done->push(true);
            });

            Coroutine::create(function () use ($base, $sandbox, &$ran, $aRegistered, $bTerminated, $done) {
                $aRegistered->pop();
                Context::set('octane.request_scope', new RequestScope($base, Request::create('/b')));
                $sandbox->terminating(function () use (&$ran) {
                    $ran['b'][] = 'b';
                });
                $sandbox->terminate();
                $bTerminated->push(true);
                $done->push(true);
            });

            $done->pop();
            $done->pop();
        });

        $this->assertSame(['a' => [], 'b' => ['b']], $ran);
        $this->assertSame([], $this->baseTerminatingCallbacks($base));
    }

    public function test_an_after_response_job_runs_once_and_not_again_in_later_requests(): void
    {
        [$base, $sandbox] = $this->applications();
        $base->singleton(Dispatcher::class, fn ($app) => new Dispatcher($app));
        $base->alias(Dispatcher::class, DispatcherContract::class);
        $base->make(Dispatcher::class);
        $job = new CountingJob;

        Context::set('octane.request_scope', $first = new RequestScope($base, Request::create('/js-error')));
        $sandbox->make(DispatcherContract::class)->dispatchAfterResponse($job);
        $sandbox->terminate();
        $first->clear();

        foreach (['/later-1', '/later-2'] as $uri) {
            Context::set('octane.request_scope', $later = new RequestScope($base, Request::create($uri)));
            $sandbox->terminate();
            $later->clear();
        }

        $this->assertSame(1, $job->runs);
        $this->assertSame([], $this->baseTerminatingCallbacks($base));
    }

    public function test_outside_a_request_terminating_registers_on_the_base_application(): void
    {
        [$base, $sandbox] = $this->applications();

        $sandbox->terminating(fn () => null);

        $this->assertCount(1, $this->baseTerminatingCallbacks($base));
    }

    /**
     * @return array{0: \Illuminate\Foundation\Application, 1: \Laravel\Octane\Swoole\Coroutine\CoroutineApplication}
     */
    private function applications(): array
    {
        $base = new Application(__DIR__);

        return [$base, new CoroutineApplication($base)];
    }

    private function baseTerminatingCallbacks(Application $base): array
    {
        return (new ReflectionProperty($base, 'terminatingCallbacks'))->getValue($base);
    }
}

class CountingJob
{
    public int $runs = 0;

    public function handle(): void
    {
        $this->runs++;
    }
}
