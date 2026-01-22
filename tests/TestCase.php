<?php

namespace Tests;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\OctaneServiceProvider;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Worker;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

class TestCase extends OrchestraTestCase
{
    protected ?Container $previousContainer = null;
    protected $previousFacadeApp = null;

    protected function getPackageProviders($app): array
    {
        return [OctaneServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.debug', true);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('queue.default', 'sync');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApp = Facade::getFacadeApplication();

        if (extension_loaded('swoole')) {
            $proxy = new CoroutineApplication($this->app);
            Container::setInstance($proxy);
            Facade::setFacadeApplication($proxy);
            Facade::clearResolvedInstances();
        }
    }

    protected function tearDown(): void
    {
        if ($this->previousContainer) {
            Container::setInstance($this->previousContainer);
        }

        Facade::setFacadeApplication($this->previousFacadeApp);
        Facade::clearResolvedInstances();

        parent::tearDown();
    }

    protected function createWorker(FakeClient $client): Worker
    {
        $factory = new class($this->app) extends ApplicationFactory {
            public function __construct(private Application $app)
            {
                parent::__construct($app->basePath());
            }

            public function createApplication(array $initialInstances = []): Application
            {
                foreach ($initialInstances as $key => $value) {
                    $this->app->instance($key, $value);
                }

                return $this->app;
            }

            public function warm(Application $app, array $services = []): Application
            {
                return $app;
            }
        };

        return new Worker($factory, $client);
    }
}
