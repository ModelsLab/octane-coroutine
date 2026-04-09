<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Handlers\OnWorkerStart;
use Laravel\Octane\Swoole\SwooleClient;
use Laravel\Octane\Swoole\SwooleExtension;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Worker;
use Swoole\Coroutine;
use Tests\TestCase;

class OnWorkerStartPoolFreeTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2).'/bin/WorkerState.php';

        parent::setUp();
    }

    private function requiresSwoole(): void
    {
        if (! class_exists(Coroutine::class) || ! function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }
    }

    public function test_http_worker_boot_uses_single_worker_and_does_not_create_pools_even_when_pool_config_exists(): void
    {
        $this->requiresSwoole();

        $workerState = new \Laravel\Octane\Swoole\WorkerState();
        $client = new FakeClient([]);

        $fakeWorker = $this->createMock(Worker::class);
        $fakeWorker->method('application')->willReturn($this->app);
        $fakeWorker->method('getClient')->willReturn($client);

        $handler = new TestableOnWorkerStart(
            new SwooleExtension(),
            $this->app->basePath(),
            [
                'appName' => 'octane-coroutine-test',
                'octaneConfig' => [
                    'swoole' => [
                        'pool' => ['size' => 32],
                    ],
                ],
            ],
            $workerState,
            false,
            $fakeWorker,
        );

        $server = (object) ['setting' => ['worker_num' => 4]];
        $bootedWorker = null;

        \Swoole\Coroutine\run(function () use ($handler, $server, &$bootedWorker) {
            $bootedWorker = $handler->bootHttpWorkerForTest($server, 1);
        });

        $this->assertSame($fakeWorker, $bootedWorker);
        $this->assertSame([[1, 0]], $handler->createPoolWorkerInvocations);
        $this->assertSame($fakeWorker, $workerState->worker);
        $this->assertSame($client, $workerState->client);
        $this->assertTrue($workerState->ready);
        $this->assertNull($workerState->workerPool);
        $this->assertNull($workerState->clientPool);

        $container = Container::getInstance();

        $this->assertInstanceOf(CoroutineApplication::class, $container);
        $this->assertSame($container, Facade::getFacadeApplication());
        $this->assertSame($this->app, $container->getBaseApplication());
    }
}

class TestableOnWorkerStart extends OnWorkerStart
{
    public array $createPoolWorkerInvocations = [];

    public function __construct(
        SwooleExtension $extension,
        string $basePath,
        array $serverState,
        $workerState,
        bool $shouldSetProcessName,
        private Worker $fakeWorker,
    ) {
        parent::__construct($extension, $basePath, $serverState, $workerState, $shouldSetProcessName);
    }

    public function bootHttpWorkerForTest($server, int $workerId): ?Worker
    {
        return $this->bootHttpWorker($server, $workerId);
    }

    public function createPoolWorker($server, int $workerId, int $poolIndex): Worker
    {
        $this->createPoolWorkerInvocations[] = [$workerId, $poolIndex];

        return $this->fakeWorker;
    }

    protected function warnIfDatabasePoolMinExceedsMaxConnections(Worker $worker, $server): void
    {
    }
}
