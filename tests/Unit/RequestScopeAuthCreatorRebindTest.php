<?php

namespace Tests\Unit;

use Closure;
use Illuminate\Auth\AuthManager;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionFunction;

/**
 * RequestScope rebinds custom auth guard creators onto the coroutine-local
 * AuthManager clone so Auth::viaRequest() guards see the right request.
 *
 * It must only rebind creators the AuthManager itself closed over. Sanctum
 * registers its guard from a service provider and the closure calls
 * $this->createGuard(); rebinding $this to the AuthManager routes that through
 * AuthManager::__call, which forwards to the default guard and throws
 * "Method ...::createGuard does not exist" on every authenticated API request.
 */
class RequestScopeAuthCreatorRebindTest extends TestCase
{
    private function rebind(array $creators): array
    {
        $auth = (new ReflectionClass(AuthManager::class))->newInstanceWithoutConstructor();

        $scope = (new ReflectionClass(RequestScope::class))->newInstanceWithoutConstructor();

        $setProp = new \ReflectionMethod(RequestScope::class, 'setObjectProperty');
        $setProp->setAccessible(true);
        $setProp->invoke($scope, $auth, 'customCreators', $creators);

        $method = new \ReflectionMethod(RequestScope::class, 'rebindAuthCustomCreators');
        $method->setAccessible(true);
        $method->invoke($scope, $auth);

        $getProp = new \ReflectionMethod(RequestScope::class, 'getObjectProperty');
        $getProp->setAccessible(true);

        return [$getProp->invoke($scope, $auth, 'customCreators'), $auth];
    }

    public function test_it_rebinds_creators_bound_to_the_auth_manager()
    {
        // Stand in for what Auth::viaRequest() produces: a closure created
        // inside AuthManager, so $this is the manager.
        $origin = (new ReflectionClass(AuthManager::class))->newInstanceWithoutConstructor();
        $creator = Closure::bind(function () {
            return $this;
        }, $origin, AuthManager::class);

        [$creators, $auth] = $this->rebind(['viaRequestish' => $creator]);

        $boundTo = (new ReflectionFunction($creators['viaRequestish']))->getClosureThis();

        $this->assertSame($auth, $boundTo, 'AuthManager-owned creators must be rebound to the clone.');
        $this->assertNotSame($origin, $boundTo);
    }

    public function test_it_leaves_service_provider_creators_alone()
    {
        // Stand in for Sanctum: closure bound to a provider-ish object that
        // exposes createGuard(). If it gets rebound, $this->createGuard()
        // resolves against AuthManager instead and blows up.
        $provider = new class
        {
            public function createGuard(): string
            {
                return 'sanctum-guard';
            }
        };

        $creator = Closure::bind(function () {
            return $this->createGuard();
        }, $provider, $provider::class);

        [$creators] = $this->rebind(['sanctum' => $creator]);

        $boundTo = (new ReflectionFunction($creators['sanctum']))->getClosureThis();

        $this->assertSame($provider, $boundTo, 'Provider-owned creators must keep their original $this.');
        $this->assertSame('sanctum-guard', ($creators['sanctum'])());
    }

    public function test_it_skips_non_closure_and_static_creators()
    {
        $static = static function () {
            return 'static';
        };

        [$creators] = $this->rebind(['static' => $static, 'string' => 'not-a-closure']);

        $this->assertNull((new ReflectionFunction($creators['static']))->getClosureThis());
        $this->assertSame('not-a-closure', $creators['string']);
    }
}
