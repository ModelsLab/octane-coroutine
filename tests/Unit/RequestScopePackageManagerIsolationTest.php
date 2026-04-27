<?php

namespace Diglactic\Breadcrumbs {
    use Illuminate\Contracts\View\Factory as ViewFactory;
    use Illuminate\Routing\Router;

    if (! class_exists(Generator::class)) {
        class Generator
        {
        }
    }

    if (! class_exists(Manager::class)) {
        class Manager
        {
            protected Generator $generator;

            protected Router $router;

            protected ViewFactory $viewFactory;

            protected array $callbacks = [];

            protected array $before = [];

            protected array $after = [];

            protected ?array $route = null;

            public function __construct(Generator $generator, Router $router, ViewFactory $viewFactory)
            {
                $this->generator = $generator;
                $this->router = $router;
                $this->viewFactory = $viewFactory;
            }

            public function for(string $name, callable $callback): void
            {
                $this->callbacks[$name] = $callback;
            }

            public function before(callable $callback): void
            {
                $this->before[] = $callback;
            }

            public function after(callable $callback): void
            {
                $this->after[] = $callback;
            }

            public function setCurrentRoute(string $name, ...$params): void
            {
                $this->route = [$name, $params];
            }
        }
    }
}

namespace Tests\Unit {
    use Diglactic\Breadcrumbs\Generator as BreadcrumbsGenerator;
    use Diglactic\Breadcrumbs\Manager as BreadcrumbsManager;
    use Illuminate\Config\Repository;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Foundation\Application;
    use Illuminate\Http\Request;
    use Illuminate\Routing\Router;
    use Laravel\Octane\Swoole\Coroutine\RequestScope;
    use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
    use Laravel\Socialite\SocialiteManager;
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;

    class RequestScopePackageManagerIsolationTest extends TestCase
    {
        public function test_socialite_factory_is_sandbox_bound_and_does_not_reuse_cached_providers(): void
        {
            $base = $this->applicationWithSocialiteConfig('/base');
            $sandbox = $this->applicationWithSocialiteConfig('/sandbox');

            $base->singleton(SocialiteFactory::class, fn ($app) => new SocialiteManager($app));

            /** @var \Laravel\Socialite\SocialiteManager $baseManager */
            $baseManager = $base->make(SocialiteFactory::class);
            $baseProvider = $baseManager->driver('github');

            $this->assertSame(
                $base->make('request'),
                $this->readProperty($baseProvider, 'request')
            );
            $this->assertArrayHasKey('github', $baseManager->getDrivers());

            $scope = new RequestScope($base);

            /** @var \Laravel\Socialite\SocialiteManager $manager */
            $manager = $scope->resolve(SocialiteFactory::class, $sandbox);

            $this->assertInstanceOf(SocialiteManager::class, $manager);
            $this->assertNotSame($baseManager, $manager);
            $this->assertSame($sandbox, $manager->getContainer());
            $this->assertSame($sandbox->make('config'), $this->readProperty($manager, 'config'));
            $this->assertSame([], $manager->getDrivers());

            $provider = $manager->driver('github');

            $this->assertSame(
                $sandbox->make('request'),
                $this->readProperty($provider, 'request')
            );
            $this->assertSame($manager, $scope->resolve(SocialiteManager::class, $sandbox));
        }

        public function test_breadcrumbs_manager_keeps_registered_callbacks_but_replaces_runtime_state(): void
        {
            $base = new Application(__DIR__);
            $sandbox = new Application(__DIR__);
            $baseRouter = new Router(new Dispatcher($base), $base);
            $sandboxRouter = new Router(new Dispatcher($sandbox), $sandbox);
            $baseView = $this->createMock(\Illuminate\Contracts\View\Factory::class);
            $sandboxView = $this->createMock(\Illuminate\Contracts\View\Factory::class);
            $baseGenerator = new BreadcrumbsGenerator;

            $baseManager = new BreadcrumbsManager($baseGenerator, $baseRouter, $baseView);
            $baseManager->for('pricing', static fn () => null);
            $baseManager->before(static fn () => null);
            $baseManager->after(static fn () => null);
            $baseManager->setCurrentRoute('stale.route', 'stale-param');

            $base->instance(BreadcrumbsManager::class, $baseManager);
            $sandbox->instance('router', $sandboxRouter);
            $sandbox->instance(\Illuminate\Contracts\View\Factory::class, $sandboxView);

            $scope = new RequestScope($base);

            /** @var \Diglactic\Breadcrumbs\Manager $manager */
            $manager = $scope->resolve(BreadcrumbsManager::class, $sandbox);

            $this->assertInstanceOf(BreadcrumbsManager::class, $manager);
            $this->assertNotSame($baseManager, $manager);
            $this->assertSame(['pricing'], array_keys($this->readProperty($manager, 'callbacks')));
            $this->assertCount(1, $this->readProperty($manager, 'before'));
            $this->assertCount(1, $this->readProperty($manager, 'after'));
            $this->assertNotSame($baseGenerator, $this->readProperty($manager, 'generator'));
            $this->assertSame($sandboxRouter, $this->readProperty($manager, 'router'));
            $this->assertSame($sandboxView, $this->readProperty($manager, 'viewFactory'));
            $this->assertNull($this->readProperty($manager, 'route'));
            $this->assertSame($manager, $scope->resolve(BreadcrumbsManager::class, $sandbox));
        }

        private function applicationWithSocialiteConfig(string $path): Application
        {
            $app = new Application(__DIR__);

            $app->instance('config', new Repository([
                'services' => [
                    'github' => [
                        'client_id' => 'client',
                        'client_secret' => 'secret',
                        'redirect' => 'https://example.test/oauth/github/callback',
                    ],
                ],
            ]));
            $app->instance('request', Request::create($path));

            return $app;
        }

        private function readProperty(object $object, string $property): mixed
        {
            $reflection = new ReflectionProperty($object, $property);
            $reflection->setAccessible(true);

            return $reflection->getValue($object);
        }
    }
}
