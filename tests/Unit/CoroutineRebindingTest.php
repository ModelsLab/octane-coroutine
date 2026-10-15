<?php

namespace Tests\Unit;

use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Cookie\CookieJar;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * Laravel's AuthManager calls refresh('request', $guard, 'setRequest') for every guard it
 * creates. The auth manager is request-scoped, but the proxy sent refresh() to the base
 * application: each request left a rebound callback (and the guard, request, session and
 * user it captures) on the worker for good, and the session guard was handed the base
 * application's boot request, so remember-me and basic auth read an empty request.
 */
class CoroutineRebindingTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::clear();

        parent::tearDown();
    }

    public function test_refresh_inside_a_request_returns_the_current_request_and_leaves_the_base_untouched(): void
    {
        [$base, $sandbox] = $this->applications();
        $current = Request::create('/current');
        $target = new RequestRecorder;

        Context::set('octane.request_scope', new RequestScope($base, $current));

        $this->assertSame($current, $sandbox->refresh('request', $target, 'setRequest'));
        $this->assertSame([], $this->baseReboundCallbacks($base, 'request'));
    }

    public function test_rebinding_the_request_inside_a_request_reaches_request_local_targets(): void
    {
        [$base, $sandbox] = $this->applications();
        $target = new RequestRecorder;

        Context::set('octane.request_scope', new RequestScope($base, Request::create('/first')));

        $sandbox->refresh('request', $target, 'setRequest');
        $sandbox->instance('request', $replacement = Request::create('/replacement'));

        $this->assertSame([$replacement], $target->received);
    }

    public function test_concurrent_requests_do_not_run_each_others_rebinding_callbacks(): void
    {
        if (! class_exists(Coroutine::class) || ! class_exists(Channel::class)) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        [$base, $sandbox] = $this->applications();
        $targetA = new RequestRecorder;
        $targetB = new RequestRecorder;

        Coroutine\run(function () use ($base, $sandbox, $targetA, $targetB) {
            $aRegistered = new Channel(1);
            $bRebound = new Channel(1);
            $done = new Channel(2);

            Coroutine::create(function () use ($base, $sandbox, $targetA, $aRegistered, $bRebound, $done) {
                Context::set('octane.request_scope', new RequestScope($base, Request::create('/a')));
                $sandbox->refresh('request', $targetA, 'setRequest');

                $aRegistered->push(true);
                $bRebound->pop();

                $done->push(true);
            });

            Coroutine::create(function () use ($base, $sandbox, $targetB, $aRegistered, $bRebound, $done) {
                $aRegistered->pop();

                Context::set('octane.request_scope', new RequestScope($base, Request::create('/b')));
                $sandbox->refresh('request', $targetB, 'setRequest');
                $sandbox->instance('request', Request::create('/b-replacement'));

                $bRebound->push(true);
                $done->push(true);
            });

            $done->pop();
            $done->pop();
        });

        $this->assertSame([], $targetA->received);
        $this->assertSame(['/b-replacement'], array_map(fn (Request $r) => $r->getPathInfo(), $targetB->received));
        $this->assertSame([], $this->baseReboundCallbacks($base, 'request'));
    }

    public function test_outside_a_request_refresh_still_registers_on_the_base_application(): void
    {
        [$base, $sandbox] = $this->applications();
        $base->instance('request', $bootRequest = Request::create('/boot'));

        $this->assertSame($bootRequest, $sandbox->refresh('request', new RequestRecorder, 'setRequest'));
        $this->assertCount(1, $this->baseReboundCallbacks($base, 'request'));
    }

    public function test_the_session_guard_holds_the_current_request_and_leaves_no_callback_on_the_base(): void
    {
        [$base, $sandbox] = $this->applications();
        $base->instance('request', Request::create('/boot'));
        $base->make('auth')->provider('stub', fn () => new StubUserProvider);

        $current = Request::create('/dashboard', 'GET', [], ['remember_web' => 'cookie-value']);

        Context::set('octane.request_scope', new RequestScope($base, $current));

        $guard = $sandbox->make('auth')->guard('web');

        $this->assertInstanceOf(SessionGuard::class, $guard);
        $this->assertSame($current, $guard->getRequest());
        $this->assertSame([], $this->baseReboundCallbacks($base, 'request'));
    }

    /**
     * @return array{0: \Illuminate\Foundation\Application, 1: \Laravel\Octane\Swoole\Coroutine\CoroutineApplication}
     */
    private function applications(): array
    {
        $base = new Application(__DIR__);

        $base->instance('config', new ConfigRepository([
            'auth' => [
                'defaults' => ['guard' => 'web'],
                'guards' => ['web' => ['driver' => 'session', 'provider' => 'users']],
                'providers' => ['users' => ['driver' => 'stub']],
            ],
            'session' => ['driver' => 'array', 'cookie' => 'session'],
        ]));
        $base->instance('session', new SessionManager($base));
        $base->singleton('session.store', fn ($app) => $app->make('session')->driver());
        $base->instance('cookie', new CookieJar);
        $base->instance('auth', new AuthManager($base));

        return [$base, new CoroutineApplication($base)];
    }

    /**
     * @return list<\Closure>
     */
    private function baseReboundCallbacks(Application $base, string $abstract): array
    {
        return (new ReflectionProperty($base, 'reboundCallbacks'))->getValue($base)[$abstract] ?? [];
    }
}

class RequestRecorder
{
    /**
     * @var list<\Illuminate\Http\Request>
     */
    public array $received = [];

    public function setRequest(Request $request): void
    {
        $this->received[] = $request;
    }
}

class StubUserProvider implements UserProvider
{
    public function retrieveById($identifier): ?Authenticatable
    {
        return null;
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, $token): void
    {
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        return null;
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return false;
    }

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void
    {
    }
}
