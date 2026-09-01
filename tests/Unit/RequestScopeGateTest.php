<?php

namespace Tests\Unit;

use Illuminate\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Foundation\Application;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The authorization Gate must be rebuilt per coroutine.
 *
 * Laravel registers the Gate as a singleton whose user resolver closes over the
 * application that built it — the base worker app, which never has an
 * authenticated user. Stock Octane hides this by cloning the app per request
 * and re-pointing the Gate at the clone
 * (GiveNewApplicationInstanceToAuthorizationGate). Coroutine mode does not
 * clone the app, so without a scoped Gate every policy check resolves a null
 * user and fails closed: $this->authorize() throws AuthorizationException and
 * the request 403s even though the caller is authenticated and owns the record.
 *
 * Shipped to production on 2026-08-12: list endpoints (which read
 * $request->user()) kept working while every detail endpoint 403'd.
 */
class RequestScopeGateTest extends TestCase
{
    /** Build a RequestScope whose base app exposes the given Gate. */
    private function scopeFor(Gate $baseGate): RequestScope
    {
        $app = new Application;
        $app->instance(GateContract::class, $baseGate);

        $scope = (new ReflectionClass(RequestScope::class))->newInstanceWithoutConstructor();
        $prop = new ReflectionProperty(RequestScope::class, 'app');
        $prop->setAccessible(true);
        $prop->setValue($scope, $app);

        return $scope;
    }

    /** A sandbox container whose 'auth' resolves to the given user. */
    private function sandboxFor(?object $user): Application
    {
        $sandbox = new Application;
        $sandbox->instance('auth', new class($user)
        {
            public function __construct(private ?object $user) {}

            public function user(): ?object
            {
                return $this->user;
            }
        });

        return $sandbox;
    }

    private function invokeCreateGate(RequestScope $scope, Application $sandbox)
    {
        $m = new ReflectionMethod(RequestScope::class, 'createGate');
        $m->setAccessible(true);

        return $m->invoke($scope, $sandbox);
    }

    public function test_scoped_gate_resolves_the_coroutine_user_not_the_base_app_null()
    {
        $expected = new \stdClass;

        $baseGate = new Gate(new Application, fn () => null);
        $scope = $this->scopeFor($baseGate);

        $gate = $this->invokeCreateGate($scope, $this->sandboxFor($expected));

        $resolver = new ReflectionProperty($gate, 'userResolver');
        $resolver->setAccessible(true);

        $this->assertSame($expected, ($resolver->getValue($gate))(),
            'Scoped gate must resolve the coroutine request user, not the base app null user.');
        $this->assertNotSame($baseGate, $gate, 'Scoped gate must be a distinct instance.');
    }

    public function test_scoped_gate_keeps_abilities_and_now_evaluates_them_against_the_user()
    {
        $baseGate = new Gate(new Application, fn () => null);
        $baseGate->define('edit-thing', fn ($user) => $user !== null);

        $scope = $this->scopeFor($baseGate);
        $gate = $this->invokeCreateGate($scope, $this->sandboxFor(new \stdClass));

        $this->assertTrue($gate->allows('edit-thing'),
            'Cloned gate lost its abilities, or is still resolving a null user.');
    }

    public function test_two_coroutines_get_gates_bound_to_their_own_users()
    {
        $userA = new \stdClass;
        $userB = new \stdClass;

        $baseGate = new Gate(new Application, fn () => null);
        $scope = $this->scopeFor($baseGate);

        $gateA = $this->invokeCreateGate($scope, $this->sandboxFor($userA));
        $gateB = $this->invokeCreateGate($scope, $this->sandboxFor($userB));

        $resolve = function ($gate) {
            $p = new ReflectionProperty($gate, 'userResolver');
            $p->setAccessible(true);

            return ($p->getValue($gate))();
        };

        $this->assertSame($userA, $resolve($gateA));
        $this->assertSame($userB, $resolve($gateB), 'Second coroutine inherited the first coroutine user.');
    }
}
