<?php

namespace Tests\Unit;

use Laravel\Octane\Swoole\Actions\StopWorkerIfMaxRequestsExceeded;
use Laravel\Octane\Swoole\Coroutine\Monitor;
use Laravel\Octane\Swoole\WorkerState;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class StopWorkerIfMaxRequestsExceededTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2).'/bin/WorkerState.php';

        parent::setUp();

        Monitor::clearRequestCoroutines();
    }

    protected function tearDown(): void
    {
        Monitor::clearRequestCoroutines();

        parent::tearDown();
    }

    public function test_it_does_nothing_when_cooperative_recycling_is_disabled(): void
    {
        $server = new FakeWorkerRecycleServer();
        $workerState = $this->workerState(maxRequests: 0);

        $this->assertFalse((new StopWorkerIfMaxRequestsExceeded($server, $workerState))());
        $this->assertSame(0, $workerState->handledRequests);
        $this->assertSame([], $server->stoppedWorkerIds);
    }

    public function test_it_stops_worker_after_max_requests_when_no_requests_are_active(): void
    {
        $server = new FakeWorkerRecycleServer();
        $workerState = $this->workerState(maxRequests: 3, workerId: 7);
        $action = new StopWorkerIfMaxRequestsExceeded($server, $workerState);

        $this->assertFalse($action());
        $this->assertFalse($action());
        $this->assertTrue($action());

        $this->assertSame(3, $workerState->handledRequests);
        $this->assertTrue($workerState->recycleRequested);
        $this->assertTrue($workerState->recycleTriggered);
        $this->assertSame([7], $server->stoppedWorkerIds);
    }

    public function test_it_only_stops_once_after_recycle_has_been_triggered(): void
    {
        $server = new FakeWorkerRecycleServer();
        $workerState = $this->workerState(maxRequests: 1, workerId: 2);
        $action = new StopWorkerIfMaxRequestsExceeded($server, $workerState);

        $this->assertTrue($action());
        $this->assertFalse($action());

        $this->assertSame(1, $workerState->handledRequests);
        $this->assertSame([2], $server->stoppedWorkerIds);
    }

    public function test_it_waits_for_other_active_request_coroutines_before_stopping(): void
    {
        $server = new FakeWorkerRecycleServer();
        $workerState = $this->workerState(maxRequests: 2, workerId: 4);
        $action = new StopWorkerIfMaxRequestsExceeded($server, $workerState);

        Monitor::registerRequestCoroutine(101);

        $this->assertFalse($action());
        $this->assertFalse($action());

        $this->assertTrue($workerState->recycleRequested);
        $this->assertFalse($workerState->recycleTriggered);
        $this->assertSame([], $server->stoppedWorkerIds);

        Monitor::unregisterRequestCoroutine(101);

        $this->assertTrue($action());
        $this->assertSame([4], $server->stoppedWorkerIds);
        $this->assertSame(0, Monitor::getActiveRequestCount());
    }

    public function test_it_recovers_if_swoole_refuses_to_stop_the_worker(): void
    {
        $server = new FakeWorkerRecycleServer(stopResult: false);
        $workerState = $this->workerState(maxRequests: 1, workerId: 5);
        $action = new StopWorkerIfMaxRequestsExceeded($server, $workerState);

        $this->assertFalse($action());

        $this->assertTrue($workerState->recycleRequested);
        $this->assertFalse($workerState->recycleTriggered);
        $this->assertSame([5], $server->stoppedWorkerIds);

        $server->stopResult = true;

        $this->assertTrue($action());
        $this->assertTrue($workerState->recycleTriggered);
        $this->assertSame([5, 5], $server->stoppedWorkerIds);
    }

    public function test_it_recovers_if_swoole_stop_throws(): void
    {
        $server = new FakeWorkerRecycleServer(stopException: new RuntimeException('stop failed'));
        $workerState = $this->workerState(maxRequests: 1, workerId: 6);
        $action = new StopWorkerIfMaxRequestsExceeded($server, $workerState);

        $this->assertFalse($action());

        $this->assertTrue($workerState->recycleRequested);
        $this->assertFalse($workerState->recycleTriggered);
        $this->assertSame([6], $server->stoppedWorkerIds);

        $server->stopException = null;

        $this->assertTrue($action());
        $this->assertTrue($workerState->recycleTriggered);
        $this->assertSame([6, 6], $server->stoppedWorkerIds);
    }

    public function test_repeated_request_completion_checks_do_not_leak_monitor_state(): void
    {
        $server = new FakeWorkerRecycleServer();
        $workerState = $this->workerState(maxRequests: 1000, workerId: 8);
        $action = new StopWorkerIfMaxRequestsExceeded($server, $workerState);

        for ($cid = 1; $cid <= 1000; $cid++) {
            Monitor::registerRequestCoroutine($cid);
            Monitor::unregisterRequestCoroutine($cid);
            $action();
        }

        $this->assertSame(0, Monitor::getActiveRequestCount());
        $this->assertTrue($workerState->recycleTriggered);
        $this->assertSame([8], $server->stoppedWorkerIds);
    }

    private function workerState(int $maxRequests, int $workerId = 1): WorkerState
    {
        $workerState = new WorkerState();
        $workerState->workerId = $workerId;
        $workerState->maxRequests = $maxRequests;

        return $workerState;
    }
}

class FakeWorkerRecycleServer
{
    public array $stoppedWorkerIds = [];

    public function __construct(
        public bool $stopResult = true,
        public ?Throwable $stopException = null,
    ) {
    }

    public function stop(int $workerId): bool
    {
        $this->stoppedWorkerIds[] = $workerId;

        if ($this->stopException) {
            throw $this->stopException;
        }

        return $this->stopResult;
    }
}
