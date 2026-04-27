<?php

namespace Tests\Coroutine;

use Illuminate\Auth\AuthManager;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use PHPUnit\Framework\TestCase;

class RequestScopeTest extends TestCase
{
    protected function createMockApp(): Application
    {
        $app = $this->createMock(Application::class);

        return $app;
    }

    public function test_set_and_get_binding(): void
    {
        $app = $this->createMockApp();
        $scope = new RequestScope($app);

        $scope->set('request', 'fake-request');

        $this->assertTrue($scope->has('request'));
        $this->assertSame('fake-request', $scope->get('request'));
    }

    public function test_has_returns_false_for_missing_key(): void
    {
        $app = $this->createMockApp();
        $scope = new RequestScope($app);

        $this->assertFalse($scope->has('nonexistent'));
    }

    public function test_get_returns_null_for_missing_key(): void
    {
        $app = $this->createMockApp();
        $scope = new RequestScope($app);

        $this->assertNull($scope->get('nonexistent'));
    }

    public function test_forget_removes_binding(): void
    {
        $app = $this->createMockApp();
        $scope = new RequestScope($app);

        $scope->set('session', 'fake-session');
        $this->assertTrue($scope->has('session'));

        $scope->forget('session');
        $this->assertFalse($scope->has('session'));
        $this->assertNull($scope->get('session'));
    }

    public function test_clear_removes_all_bindings(): void
    {
        $app = $this->createMockApp();
        $scope = new RequestScope($app);

        $scope->set('request', 'req1');
        $scope->set('session', 'sess1');
        $scope->set('auth', 'auth1');

        $this->assertCount(3, $scope->all());

        $scope->clear();

        $this->assertCount(0, $scope->all());
        $this->assertFalse($scope->has('request'));
        $this->assertFalse($scope->has('session'));
        $this->assertFalse($scope->has('auth'));
    }

    public function test_clearing_one_scope_does_not_affect_another(): void
    {
        $app = $this->createMockApp();

        $scope1 = new RequestScope($app);
        $scope2 = new RequestScope($app);

        $scope1->set('request', 'req-alpha');
        $scope2->set('request', 'req-bravo');

        $scope1->clear();

        // scope1 is cleared
        $this->assertFalse($scope1->has('request'));
        $this->assertNull($scope1->get('request'));

        // scope2 is unaffected
        $this->assertTrue($scope2->has('request'));
        $this->assertSame('req-bravo', $scope2->get('request'));
    }

    public function test_all_returns_all_bindings(): void
    {
        $app = $this->createMockApp();
        $scope = new RequestScope($app);

        $scope->set('request', 'r');
        $scope->set('auth', 'a');

        $all = $scope->all();

        $this->assertArrayHasKey('request', $all);
        $this->assertArrayHasKey('auth', $all);
        $this->assertSame('r', $all['request']);
        $this->assertSame('a', $all['auth']);
    }

    public function test_get_app_returns_base_application(): void
    {
        $app = $this->createMockApp();
        $scope = new RequestScope($app);

        $this->assertSame($app, $scope->getApp());
    }

    public function test_config_cloned_flag_is_initially_false(): void
    {
        $app = $this->createMockApp();
        $scope = new RequestScope($app);

        $this->assertFalse($scope->isConfigCloned());
    }

    public function test_clear_resets_config_cloned_flag(): void
    {
        $app = $this->createMockApp();
        $scope = new RequestScope($app);

        // Manually set config to simulate cloning
        $scope->set('config', 'cloned-config');

        $scope->clear();

        $this->assertFalse($scope->isConfigCloned());
        $this->assertFalse($scope->has('config'));
    }

    public function test_overwriting_binding_replaces_value(): void
    {
        $app = $this->createMockApp();
        $scope = new RequestScope($app);

        $scope->set('auth', 'user-1');
        $this->assertSame('user-1', $scope->get('auth'));

        $scope->set('auth', 'user-2');
        $this->assertSame('user-2', $scope->get('auth'));
    }

    public function test_has_returns_true_for_null_value(): void
    {
        $app = $this->createMockApp();
        $scope = new RequestScope($app);

        // array_key_exists returns true even for null values
        $scope->set('cookie', null);

        $this->assertTrue($scope->has('cookie'));
        $this->assertNull($scope->get('cookie'));
    }

    public function test_cloned_auth_manager_resolves_users_from_the_cloned_guard(): void
    {
        $app = new Application(__DIR__);
        $app->instance('auth', new RequestScopeFakeAuthManager($app));

        $scope = new RequestScope($app);
        $auth = $scope->resolve('auth', $app);

        $auth->attempt(['id' => 123]);

        $resolver = $auth->userResolver();

        $this->assertSame(123, $resolver()->id);
    }

    public function test_cloned_auth_manager_custom_request_guards_use_scoped_request(): void
    {
        $baseApp = new Application(__DIR__);
        $baseApp->instance('config', new Repository([
            'auth' => [
                'defaults' => [
                    'guard' => 'api',
                    'provider' => null,
                ],
                'guards' => [
                    'api' => [
                        'driver' => 'api-key',
                    ],
                ],
            ],
        ]));
        $baseApp->instance('request', Request::create('/base', 'POST', ['key' => 'base-key']));

        $baseAuth = new AuthManager($baseApp);
        $baseAuth->viaRequest('api-key', static function (Request $request) {
            return (object) [
                'key' => $request->input('key'),
                'path' => $request->path(),
            ];
        });
        $baseApp->instance('auth', $baseAuth);

        $scopedRequest = Request::create('/scoped', 'POST', ['key' => 'scoped-key']);
        $sandbox = new Application(__DIR__);
        $sandbox->instance('config', $baseApp->make('config'));
        $sandbox->instance('request', $scopedRequest);

        $scope = new RequestScope($baseApp, $scopedRequest);
        $auth = $scope->resolve('auth', $sandbox);
        $user = $auth->guard('api')->user();

        $this->assertSame('scoped-key', $user->key);
        $this->assertSame('scoped', $user->path);
    }
}

class RequestScopeFakeAuthManager
{
    private $userResolver;

    public ?object $user = null;

    public function __construct(public Application $app)
    {
        $this->userResolver = fn ($guard = null) => $this->guard($guard)->user();
    }

    public function setApplication(Application $app): static
    {
        $this->app = $app;

        return $this;
    }

    public function resolveUsersUsing(\Closure $userResolver): static
    {
        $this->userResolver = $userResolver;

        return $this;
    }

    public function forgetGuards(): void
    {
    }

    public function guard($guard = null): static
    {
        return $this;
    }

    public function attempt(array $credentials): bool
    {
        $this->user = (object) ['id' => $credentials['id']];

        return true;
    }

    public function user(): ?object
    {
        return $this->user;
    }

    public function userResolver(): \Closure
    {
        return $this->userResolver;
    }
}
