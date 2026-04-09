<?php

namespace Tests\Unit;

use Illuminate\Contracts\View\Factory as ViewFactoryContract;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\ViewFinderInterface;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use PHPUnit\Framework\TestCase;

class RequestScopeViewIsolationTest extends TestCase
{
    public function test_view_factory_shared_data_is_isolated_per_request_scope(): void
    {
        $base = new Application(__DIR__);

        $view = new Factory(
            new EngineResolver,
            $this->createMock(ViewFinderInterface::class),
            new Dispatcher($base)
        );
        $view->setContainer($base);
        $view->share('boot_only', 'global');

        $base->instance('view', $view);
        $base->instance(ViewFactoryContract::class, $view);

        $sandbox = new CoroutineApplication($base);

        Context::set('octane.request_scope', new RequestScope($base));

        try {
            /** @var \Illuminate\View\Factory $firstView */
            $firstView = $sandbox->make('view');
            $firstView->share('request_id', 'alpha');

            $this->assertSame('alpha', $firstView->shared('request_id'));
            $this->assertSame('global', $firstView->shared('boot_only'));
            $this->assertSame($sandbox, $firstView->shared('app'));
            $this->assertNull($base->make('view')->shared('request_id'));
        } finally {
            Context::clear();
        }

        Context::set('octane.request_scope', new RequestScope($base));

        try {
            /** @var \Illuminate\View\Factory $secondView */
            $secondView = $sandbox->make('view');

            $this->assertSame('global', $secondView->shared('boot_only'));
            $this->assertNull($secondView->shared('request_id'));
            $this->assertNull($base->make('view')->shared('request_id'));
            $this->assertSame($secondView, $sandbox->make(ViewFactoryContract::class));
        } finally {
            Context::clear();
        }
    }
}
