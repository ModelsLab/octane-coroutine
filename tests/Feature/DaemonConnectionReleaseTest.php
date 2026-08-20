<?php

namespace Tests\Feature;

use ArrayObject;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Database\DatabaseManager;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Tests\TestCase;

class DaemonConnectionReleaseTest extends TestCase
{
    /**
     * The exit hook must collect the coroutine's cycle garbage BEFORE
     * releasing its connections, so statements hidden in cycles close
     * against an idle connection instead of one another coroutine has
     * already borrowed (which permanently leaks the server-side statement).
     *
     * @requires extension swoole
     */
    public function test_exit_hook_collects_cycle_garbage_before_releasing(): void
    {
        if (! class_exists(Coroutine::class) || ! function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $events = new ArrayObject();

        \Swoole\Coroutine\run(function () use ($events) {
            $done = new Channel(1);

            Coroutine::create(function () use ($events, $done) {
                app('db')->connection()->select('select 1'); // arms the exit hook

                Context::set('db.connection.probe', new \stdClass());
                Context::set('db.connection.probe.pool', new ReleaseOrderRecordingPool($events));

                $probe = new ReleaseOrderDestructProbe($events);
                $probe->self = $probe;
                unset($probe);

                $done->push(true);
            });

            $done->pop();
            Coroutine::sleep(0.05);
        });

        $this->assertSame(
            ['destruct', 'release'],
            iterator_to_array($events),
            'Cycle garbage must die (destruct) before the exit hook releases the connection (release).'
        );
    }

    /**
     * The exit hook's release walk runs on every DB-using request coroutine
     * after the Worker has already emptied the context; it must not pay a
     * full all-pool prune for that no-op.
     *
     * @requires extension swoole
     */
    public function test_release_walk_skips_pruning_when_nothing_was_held(): void
    {
        if (! class_exists(Coroutine::class) || ! function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $events = new ArrayObject();
        $pool = new ReleaseOrderRecordingPool($events);

        $manager = app('db');
        $this->assertInstanceOf(DatabaseManager::class, $manager);

        $pools = new \ReflectionProperty(DatabaseManager::class, 'pools');
        $originalPools = $pools->getValue($manager);
        $pools->setValue($manager, ['recording' => $pool]);

        try {
            \Swoole\Coroutine\run(function () use ($manager, $events, $pool) {
                $manager->releaseConnections();
                $events['after_empty'] = $pool->pruneCalls;

                Context::set('db.connection.probe', new \stdClass());
                Context::set('db.connection.probe.pool', $pool);
                $manager->releaseConnections();
                $events['after_held'] = $pool->pruneCalls;
            });
        } finally {
            $pools->setValue($manager, $originalPools);
        }

        $this->assertSame(0, $events['after_empty'], 'An empty context walk must not prune every pool.');
        $this->assertGreaterThan(0, $events['after_held'], 'A walk that released something must still prune.');
    }

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
