<?php

namespace Tests\Unit;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
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
}

class CoroutineApplicationBuildRequestProbe
{
    public function __construct(public Request $request)
    {
    }
}
