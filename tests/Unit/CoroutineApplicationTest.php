<?php

namespace Tests\Unit;

use Illuminate\Contracts\Validation\Factory as ValidationFactoryContract;
use Illuminate\Contracts\Validation\ValidatesWhenResolved;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use PHPUnit\Framework\TestCase;

class CoroutineApplicationTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::clear();
        parent::tearDown();
    }

    public function test_make_uses_base_app_outside_coroutine(): void
    {
        $base = new Application(__DIR__);
        $base->instance('test.value', 'base');

        $proxy = new CoroutineApplication($base);

        $this->assertSame('base', $proxy->make('test.value'));
    }

    public function test_make_uses_context_app_inside_coroutine(): void
    {
        if (!class_exists(\Swoole\Coroutine::class) || !function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $base = new Application(__DIR__);
        $base->instance('test.value', 'base');

        $sandbox = new Application(__DIR__);
        $sandbox->instance('test.value', 'sandbox');

        $proxy = new CoroutineApplication($base);
        $result = null;

        \Swoole\Coroutine\run(function () use ($sandbox, $proxy, &$result) {
            Context::set('octane.app', $sandbox);
            $result = $proxy->make('test.value');
        });

        $this->assertSame('sandbox', $result);
    }

    public function test_build_resolves_nested_dependencies_through_request_scope_inside_coroutine(): void
    {
        if (!class_exists(\Swoole\Coroutine::class) || !function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $base = new Application(__DIR__);
        $baseRequest = Request::create('/base?request_id=base', 'GET', [], [], [], [
            'HTTP_USER_AGENT' => 'base-agent',
        ]);

        $base->instance('request', $baseRequest);
        $base->alias('request', Request::class);
        $base->alias('request', \Symfony\Component\HttpFoundation\Request::class);

        $proxy = new CoroutineApplication($base);
        $result = null;

        \Swoole\Coroutine\run(function () use ($base, $proxy, &$result) {
            $scopedRequest = Request::create('/scoped?request_id=scoped', 'GET', [], [], [], [
                'HTTP_USER_AGENT' => 'scoped-agent',
            ]);

            Context::set('octane.request_scope', new RequestScope($base, $scopedRequest));

            $builtProbe = $proxy->build(CoroutineApplicationBuildRequestProbe::class);
            $madeProbe = $proxy->make(CoroutineApplicationBuildRequestProbe::class);

            $result = [
                'built_request_id' => $builtProbe->request->query('request_id'),
                'built_user_agent' => $builtProbe->request->userAgent(),
                'made_request_id' => $madeProbe->request->query('request_id'),
                'made_user_agent' => $madeProbe->request->userAgent(),
            ];
        });

        $this->assertSame([
            'built_request_id' => 'scoped',
            'built_user_agent' => 'scoped-agent',
            'made_request_id' => 'scoped',
            'made_user_agent' => 'scoped-agent',
        ], $result);
    }

    public function test_scoped_unbound_concretes_fire_base_resolving_callbacks(): void
    {
        $base = new Application(__DIR__);
        $base->afterResolving(CoroutineApplicationResolvingProbeContract::class, function ($resolved) {
            $resolved->resolvedByCallback = true;
        });

        $proxy = new CoroutineApplication($base);

        Context::set('octane.request_scope', new RequestScope($base, Request::create('/scoped')));

        $probe = $proxy->make(CoroutineApplicationResolvingProbe::class);

        $this->assertTrue($probe->resolvedByCallback);
    }

    public function test_scoped_form_requests_are_prepared_and_validated_by_base_callbacks(): void
    {
        $base = new Application(__DIR__);
        $base->instance('request', Request::create('/base', 'POST'));
        $base->alias('request', Request::class);
        $base->alias('request', \Symfony\Component\HttpFoundation\Request::class);

        $translator = new Translator(new ArrayLoader, 'en');
        $validationFactory = new ValidationFactory($translator);
        $base->instance(ValidationFactoryContract::class, $validationFactory);

        $base->resolving(FormRequest::class, function ($request, $app) {
            FormRequest::createFrom($app['request'], $request);
            $request->setContainer($app);
        });

        $base->afterResolving(ValidatesWhenResolved::class, function ($resolved) {
            $resolved->validateResolved();
        });

        $proxy = new CoroutineApplication($base);
        $validationFactory->setContainer($proxy);

        Context::set('octane.request_scope', new RequestScope($base, Request::create('/login', 'POST', [
            'email' => 'valid@example.com',
            'password' => 'secret-password',
        ])));

        $request = $proxy->make(CoroutineApplicationLoginFormRequestProbe::class);

        $this->assertSame('valid@example.com', $request->validated('email'));
        $this->assertSame('secret-password', $request->validated('password'));
    }

    public function test_scoped_request_instances_fire_base_rebinding_callbacks(): void
    {
        $base = new Application(__DIR__);
        $base->instance('request', Request::create('/base'));

        $base->rebinding('request', function ($app, Request $request) {
            $request->setUserResolver(fn () => new CoroutineApplicationRequestUserProbe(1));
            $request->attributes->set('resolved_path', $app->make('request')->path());
        });

        $proxy = new CoroutineApplication($base);
        $request = Request::create('/login', 'POST');

        Context::set('octane.request_scope', new RequestScope($base));

        $proxy->instance('request', $request);

        $this->assertSame(1, $request->user()->verified);
        $this->assertSame('login', $request->attributes->get('resolved_path'));
    }
}

class CoroutineApplicationBuildRequestProbe
{
    public function __construct(public Request $request)
    {
    }
}

interface CoroutineApplicationResolvingProbeContract
{
}

class CoroutineApplicationResolvingProbe implements CoroutineApplicationResolvingProbeContract
{
    public bool $resolvedByCallback = false;
}

class CoroutineApplicationLoginFormRequestProbe extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:6'],
        ];
    }
}

class CoroutineApplicationRequestUserProbe
{
    public function __construct(public int $verified)
    {
    }
}
