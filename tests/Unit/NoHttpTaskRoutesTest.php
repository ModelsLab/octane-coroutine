<?php

namespace Tests\Unit;

use Laravel\Octane\Facades\Octane;
use Laravel\Octane\SequentialTaskDispatcher;
use Tests\TestCase;

/**
 * Octane used to register POST /octane/resolve-tasks and /octane/dispatch-tasks, which ran
 * unserialize(Crypt::decryptString(input)). Anyone holding APP_KEY could run code through
 * them, and in Nov 2026 attackers did, with a key leaked through git. They must not exist.
 */
class NoHttpTaskRoutesTest extends TestCase
{
    public function test_the_task_routes_are_not_registered(): void
    {
        foreach (['/octane/resolve-tasks', '/octane/dispatch-tasks'] as $uri) {
            $this->assertFalse(Octane::hasRouteFor('POST', $uri), $uri.' must not be routable');
            $this->assertFalse(Octane::hasRouteFor('GET', $uri), $uri.' must not be routable');
        }
    }

    public function test_tasks_outside_a_server_run_inline(): void
    {
        $this->assertInstanceOf(SequentialTaskDispatcher::class, Octane::tasks());
        $this->assertSame([2, 3], Octane::concurrently([fn () => 1 + 1, fn () => 1 + 2]));
    }
}
