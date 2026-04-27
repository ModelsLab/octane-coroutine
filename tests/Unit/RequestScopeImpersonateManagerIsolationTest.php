<?php

namespace Lab404\Impersonate\Services {
    use Illuminate\Foundation\Application;

    if (! class_exists(ImpersonateManager::class)) {
        class ImpersonateManager
        {
            private Application $app;

            public function __construct(Application $app)
            {
                $this->app = $app;
            }
        }
    }
}

namespace Tests\Unit {
    use Illuminate\Foundation\Application;
    use Laravel\Octane\Swoole\Coroutine\RequestScope;
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;

    class RequestScopeImpersonateManagerIsolationTest extends TestCase
    {
        public function test_lab404_impersonate_manager_uses_coroutine_sandbox_application(): void
        {
            $base = new Application(__DIR__);
            $sandbox = new Application(__DIR__);
            $scope = new RequestScope($base);

            $manager = $scope->resolve('Lab404\\Impersonate\\Services\\ImpersonateManager', $sandbox);

            $this->assertInstanceOf('Lab404\\Impersonate\\Services\\ImpersonateManager', $manager);
            $this->assertSame($sandbox, $this->readApplication($manager));
            $this->assertSame($manager, $scope->resolve('Lab404\\Impersonate\\Services\\ImpersonateManager', $sandbox));
        }

        private function readApplication(object $manager): Application
        {
            $property = new ReflectionProperty($manager, 'app');
            $property->setAccessible(true);

            return $property->getValue($manager);
        }
    }
}
