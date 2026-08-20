<?php

namespace Tests\Feature;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Tests\TestCase;

class DaemonConnectionReleaseTest extends TestCase
{
    /**
     * Connections borrowed by coroutines the Worker never tears down (child
     * coroutines spawned by app code, coroutine-based daemons) must return
     * to the pool when their coroutine ends. Without that they bypass
     * release() entirely: the pool counter drifts one slot per borrow until
     * false "Connection pool exhausted", and long-lived borrowers accumulate
     * leaked server-side prepared statements that max_lifetime recycling can
     * never reach.
     *
     * @requires extension swoole
     */
    public function test_connection_borrowed_by_plain_coroutine_returns_to_pool_at_exit(): void
    {
        if (! class_exists(Coroutine::class) || ! function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $stats = null;

        \Swoole\Coroutine\run(function () use (&$stats) {
            $done = new Channel(1);

            Coroutine::create(function () use ($done) {
                $connection = app('db')->connection();
                $connection->select('select 1');
                $done->push(true);
                // Ends without releasing - the exit hook must return the
                // borrow to the pool.
            });

            $done->pop();
            Coroutine::sleep(0.05);

            $stats = app('db')->poolStats();
        });

        $this->assertNotEmpty($stats);
        $pool = array_values($stats)[0];

        $this->assertSame(1, $pool['current_connections'], 'The borrow must survive as a pooled connection, not vanish.');
        $this->assertSame(
            1,
            $pool['available_connections'],
            'The connection must be RETURNED to the pool at coroutine exit - an empty channel means the borrow leaked.'
        );
    }

    /**
     * Many short-lived borrowing coroutines must not drift the pool counter
     * toward exhaustion. Before the exit hook existed, each iteration leaked
     * one slot and the pool died after max_connections coroutines.
     *
     * @requires extension swoole
     */
    public function test_many_borrowing_coroutines_do_not_exhaust_the_pool(): void
    {
        if (! class_exists(Coroutine::class) || ! function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $failures = 0;
        $stats = null;

        \Swoole\Coroutine\run(function () use (&$failures, &$stats) {
            // Default pool max_connections is 10; 30 sequential borrowing
            // coroutines exhaust a drifting pool three times over.
            for ($i = 0; $i < 30; $i++) {
                $done = new Channel(1);

                Coroutine::create(function () use ($done) {
                    try {
                        app('db')->connection()->select('select 1');
                        $done->push(true);
                    } catch (\Throwable $e) {
                        $done->push(false);
                    }
                });

                if ($done->pop() !== true) {
                    $failures++;
                }

                Coroutine::sleep(0.001);
            }

            $stats = app('db')->poolStats();
        });

        $this->assertSame(0, $failures, 'Every borrowing coroutine must get a connection - drift means later ones starve.');

        $pool = array_values($stats)[0];
        $this->assertLessThanOrEqual(
            2,
            $pool['current_connections'],
            'Connections must be reused across coroutines, not leaked one per coroutine.'
        );
    }
}
