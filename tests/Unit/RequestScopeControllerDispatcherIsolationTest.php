<?php

namespace Tests\Fixtures {
    use Illuminate\Routing\ControllerDispatcher;

    class CustomControllerDispatcher extends ControllerDispatcher
    {
    }
}

namespace Tests\Unit {
    use Illuminate\Foundation\Application;
    use Illuminate\Http\Request;
    use Illuminate\Routing\ControllerDispatcher as LaravelControllerDispatcher;
    use Illuminate\Routing\Contracts\ControllerDispatcher;
    use Laravel\Octane\Swoole\Coroutine\RequestScope;
    use PHPUnit\Framework\TestCase;
    use Tests\Fixtures\CustomControllerDispatcher;

    class RequestScopeControllerDispatcherIsolationTest extends TestCase
    {
        public function test_it_honors_custom_controller_dispatcher_binding_from_base_app(): void
        {
            $base = new Application;
            $base->bind(ControllerDispatcher::class, CustomControllerDispatcher::class);

            $sandbox = new Application;
            $scope = new RequestScope($base, Request::create('/login', 'GET'));

            $dispatcher = $scope->resolve(ControllerDispatcher::class, $sandbox);

            $this->assertInstanceOf(CustomControllerDispatcher::class, $dispatcher);
        }

        public function test_it_falls_back_to_laravel_controller_dispatcher(): void
        {
            $base = new Application;
            $sandbox = new Application;
            $scope = new RequestScope($base, Request::create('/login', 'GET'));

            $dispatcher = $scope->resolve(ControllerDispatcher::class, $sandbox);

            $this->assertInstanceOf(LaravelControllerDispatcher::class, $dispatcher);
            $this->assertNotInstanceOf(CustomControllerDispatcher::class, $dispatcher);
        }
    }
}
