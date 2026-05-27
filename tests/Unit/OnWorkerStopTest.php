<?php

namespace Tests\Unit;

use Laravel\Octane\Swoole\Coroutine\Monitor;
use Laravel\Octane\Swoole\Handlers\OnWorkerStop;
use PHPUnit\Framework\TestCase;

class OnWorkerStopTest extends TestCase
{
    protected function tearDown(): void
    {
        Monitor::clearRequestCoroutines();

        parent::tearDown();
    }

    public function test_worker_stop_is_safe_outside_a_coroutine(): void
    {
        Monitor::registerRequestCoroutine(123);

        $server = (object) [
            'setting' => [
                'worker_num' => 2,
            ],
        ];

        (new OnWorkerStop(0))($server, 1);

        $this->assertSame(0, Monitor::getActiveRequestCount());
    }
}
