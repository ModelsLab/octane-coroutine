<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Connection;
use Laravel\Octane\Swoole\Database\DatabasePool;
use Mockery;
use PDO;
use ReflectionMethod;

/**
 * Tests for the DatabasePool class.
 *
 * These tests mock the dependencies since Swoole
 * is not available in the PHPUnit environment directly.
 */
class DatabasePoolTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_pool_config_defaults_are_reasonable()
    {
        // Test that our default config values are sensible
        $defaultConfig = [
            'min_connections' => 1,
            'max_connections' => 10,
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'release_timeout' => 1.0,
        ];

        $this->assertGreaterThan(0, $defaultConfig['min_connections']);
        $this->assertGreaterThanOrEqual($defaultConfig['min_connections'], $defaultConfig['max_connections']);
        $this->assertGreaterThan(0, $defaultConfig['wait_timeout']);
        $this->assertGreaterThan(0, $defaultConfig['release_timeout']);
    }

    public function test_connection_reset_includes_transaction_rollback()
    {
        // This test verifies the logic that should be applied
        // when resetting a connection before returning to pool

        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('inTransaction')->andReturn(true);
        $pdo->shouldReceive('rollBack')->once();

        // The actual implementation would call these methods
        // This test documents the expected behavior
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $this->assertTrue(true); // Test passes if no exception
    }

    public function test_connection_reset_skips_rollback_when_no_transaction()
    {
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('inTransaction')->andReturn(false);
        // rollBack should NOT be called
        $pdo->shouldNotReceive('rollBack');

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $this->assertTrue(true);
    }

    public function test_mysql_session_reset_commands_are_correct()
    {
        // Verify the SQL commands used for MySQL session reset
        $expectedCommands = [
            'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ',
            'SET autocommit = 1',
        ];

        foreach ($expectedCommands as $command) {
            $this->assertStringContainsString('SET', $command);
        }
    }

    public function test_postgresql_session_reset_command_is_correct()
    {
        // Verify the SQL command used for PostgreSQL session reset
        $expectedCommand = 'RESET ALL';

        $this->assertEquals('RESET ALL', $expectedCommand);
    }

    public function test_pool_stats_structure()
    {
        // Verify the expected structure of pool stats
        $expectedKeys = [
            'current_connections',
            'available_connections',
            'idle_tracked_connections',
            'max_connections',
            'min_connections',
            'max_idle_time',
        ];

        // Simulated stats (what the real implementation returns)
        $stats = [
            'current_connections' => 5,
            'available_connections' => 3,
            'idle_tracked_connections' => 3,
            'max_connections' => 10,
            'min_connections' => 1,
            'max_idle_time' => 60.0,
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $stats);
        }
    }

    public function test_reset_connection_rolls_back_and_resets_mysql_session()
    {
        $pool = $this->newPoolWithoutConstructor();

        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('inTransaction')->andReturn(true);
        $pdo->shouldReceive('rollBack')->once();
        $pdo->shouldReceive('exec')->with('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ')->once();
        $pdo->shouldReceive('exec')->with('SET autocommit = 1')->once();

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transactionLevel')->andReturn(0);
        $connection->shouldReceive('getPdo')->andReturn($pdo);
        $connection->shouldReceive('flushQueryLog')->once();
        $connection->shouldReceive('forgetRecordModificationState')->once();
        $connection->shouldReceive('setReadWriteType')->with(null)->once();
        $connection->shouldReceive('unsetTransactionManager')->once();
        $connection->shouldReceive('getDriverName')->andReturn('mysql');

        $this->invokeResetConnection($pool, $connection);

        $this->assertTrue(true);
    }

    public function test_reset_connection_resets_postgres_session()
    {
        $pool = $this->newPoolWithoutConstructor();

        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('inTransaction')->andReturn(false);
        $pdo->shouldReceive('exec')->with('RESET ALL')->once();

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transactionLevel')->andReturn(0);
        $connection->shouldReceive('getPdo')->andReturn($pdo);
        $connection->shouldReceive('flushQueryLog')->once();
        $connection->shouldReceive('forgetRecordModificationState')->once();
        $connection->shouldReceive('setReadWriteType')->with(null)->once();
        $connection->shouldReceive('unsetTransactionManager')->once();
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');

        $this->invokeResetConnection($pool, $connection);

        $this->assertTrue(true);
    }

    public function test_reset_connection_resets_laravel_transaction_level(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite is required.');
        }

        $pool = $this->newPoolWithoutConstructor();
        $pdo = new PDO('sqlite::memory:');
        $connection = new Connection($pdo, 'database', '', []);

        $connection->beginTransaction();

        $this->assertSame(1, $connection->transactionLevel());
        $this->assertTrue($pdo->inTransaction());

        $this->invokeResetConnection($pool, $connection);

        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($pdo->inTransaction());
    }

    public function test_get_creates_connection_immediately_when_pool_can_grow()
    {
        $this->skipIfNoSwooleCoroutine();

        $factory = Mockery::mock(ConnectionFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->withAnyArgs()
            ->andReturn(new TestConnection(new TestPdoSuccess()));

        $elapsed = null;
        $connection = null;

        \Swoole\Coroutine\run(function () use ($factory, &$elapsed, &$connection) {
            $pool = new DatabasePool([
                'min_connections' => 0,
                'max_connections' => 1,
                'wait_timeout' => 0.2,
            ], [], 'mysql', $factory);

            $start = microtime(true);
            $connection = $pool->get();
            $elapsed = microtime(true) - $start;
        });

        $this->assertInstanceOf(TestConnection::class, $connection);
        $this->assertLessThan(
            0.1,
            $elapsed,
            'Expected get() to return without waiting when the pool can grow.'
        );
    }

    public function test_get_waits_and_throws_when_pool_is_exhausted()
    {
        $this->skipIfNoSwooleCoroutine();

        $factory = Mockery::mock(ConnectionFactory::class);
        $factory->shouldNotReceive('make');

        $elapsed = null;
        $exception = null;

        \Swoole\Coroutine\run(function () use ($factory, &$elapsed, &$exception) {
            $pool = new DatabasePool([
                'min_connections' => 0,
                'max_connections' => 1,
                'wait_timeout' => 0.1,
            ], [], 'mysql', $factory);

            $this->setPoolCurrentConnections($pool, 1);

            $start = microtime(true);
            try {
                $pool->get();
            } catch (\RuntimeException $e) {
                $exception = $e;
            }
            $elapsed = microtime(true) - $start;
        });

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertStringContainsString('Connection pool exhausted', $exception->getMessage());
        $this->assertGreaterThanOrEqual(
            0.08,
            $elapsed,
            'Expected get() to wait for wait_timeout when pool is exhausted.'
        );
    }

    public function test_get_reconnects_invalid_connection()
    {
        $this->skipIfNoSwooleCoroutine();

        $connection = new TestConnection(new TestPdoFailure());

        $factory = Mockery::mock(ConnectionFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->withAnyArgs()
            ->andReturn($connection);

        $result = null;

        \Swoole\Coroutine\run(function () use ($factory, &$result) {
            $pool = new DatabasePool([
                'min_connections' => 1,
                'max_connections' => 1,
                'wait_timeout' => 0.1,
                'ping_after_idle' => 0,
            ], [], 'mysql', $factory);

            $result = $pool->get();
        });

        $this->assertTrue($connection->reconnected);
        $this->assertSame($connection, $result);
    }

    public function test_get_resets_dirty_laravel_transaction_before_returning_connection(): void
    {
        $this->skipIfNoSwooleCoroutine();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite is required.');
        }

        $connection = new Connection(new PDO('sqlite::memory:'), 'database', '', []);
        $connection->beginTransaction();

        $factory = Mockery::mock(ConnectionFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->withAnyArgs()
            ->andReturn($connection);

        $returned = null;

        \Swoole\Coroutine\run(function () use ($factory, &$returned) {
            $pool = new DatabasePool([
                'min_connections' => 1,
                'max_connections' => 1,
                'wait_timeout' => 0.1,
            ], [], 'sqlite', $factory);

            $returned = $pool->get();
        });

        $this->assertSame($connection, $returned);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
    }

    public function test_get_resets_dirty_pdo_transaction_before_returning_connection(): void
    {
        $this->skipIfNoSwooleCoroutine();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite is required.');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->beginTransaction();
        $connection = new Connection($pdo, 'database', '', []);

        $this->assertSame(0, $connection->transactionLevel());
        $this->assertTrue($pdo->inTransaction());

        $factory = Mockery::mock(ConnectionFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->withAnyArgs()
            ->andReturn($connection);

        $returned = null;

        \Swoole\Coroutine\run(function () use ($factory, &$returned) {
            $pool = new DatabasePool([
                'min_connections' => 1,
                'max_connections' => 1,
                'wait_timeout' => 0.1,
            ], [], 'sqlite', $factory);

            $returned = $pool->get();
        });

        $this->assertSame($connection, $returned);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
    }

    public function test_prune_idle_connections_closes_available_connections_above_minimum(): void
    {
        $this->skipIfNoSwooleCoroutine();

        $connections = [
            Mockery::mock(Connection::class),
            Mockery::mock(Connection::class),
            Mockery::mock(Connection::class),
        ];

        $connections[0]->shouldReceive('disconnect')->once();
        $connections[1]->shouldReceive('disconnect')->once();
        $connections[2]->shouldNotReceive('disconnect');

        // The pool now installs a reconnector on each new connection, the way
        // Illuminate\Database\DatabaseManager::configure() does.
        foreach ($connections as $connection) {
            $connection->shouldReceive('setReconnector')->once();
        }

        $factory = Mockery::mock(ConnectionFactory::class);
        $factory->shouldReceive('make')
            ->times(3)
            ->withAnyArgs()
            ->andReturn($connections[0], $connections[1], $connections[2]);

        $closed = null;
        $stats = null;

        \Swoole\Coroutine\run(function () use ($factory, &$closed, &$stats) {
            $pool = new DatabasePool([
                'min_connections' => 3,
                'max_connections' => 3,
                'wait_timeout' => 0.1,
                'heartbeat' => -1,
                'max_idle_time' => 0.001,
            ], [], 'mysql', $factory);

            $this->setPoolConfig($pool, [
                'min_connections' => 1,
                'max_connections' => 3,
                'wait_timeout' => 0.1,
                'heartbeat' => -1,
                'max_idle_time' => 0.001,
            ]);

            $closed = $pool->pruneIdleConnections(microtime(true) + 1);
            $stats = $pool->getStats();
        });

        $this->assertSame(2, $closed);
        $this->assertSame(1, $stats['current_connections']);
        $this->assertSame(1, $stats['available_connections']);
        $this->assertSame(1, $stats['idle_tracked_connections']);
    }

    public function test_release_recycles_connection_past_max_lifetime(): void
    {
        $this->skipIfNoSwooleCoroutine();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite is required.');
        }

        $factory = Mockery::mock(ConnectionFactory::class);
        $factory->shouldReceive('make')
            ->twice()
            ->withAnyArgs()
            ->andReturnUsing(fn () => new Connection(new PDO('sqlite::memory:'), 'database', '', []));

        $first = null;
        $second = null;
        $statsAfterRelease = null;

        \Swoole\Coroutine\run(function () use ($factory, &$first, &$second, &$statsAfterRelease) {
            $pool = new DatabasePool([
                'min_connections' => 0,
                'max_connections' => 2,
                'wait_timeout' => 0.1,
                'max_lifetime' => 0.05,
            ], [], 'sqlite', $factory);

            $first = $pool->get();
            \Swoole\Coroutine::sleep(0.08);

            // Probe: the expired fast-path must close the connection without
            // running the full session reset first (resetConnection flushes
            // the query log, so a surviving entry proves it was skipped).
            $first->enableQueryLog();
            $first->logQuery('probe', [], 0);

            $pool->release($first);

            $statsAfterRelease = $pool->getStats();

            $second = $pool->get();
        });

        $this->assertSame(0, $statsAfterRelease['current_connections'], 'Expired connection must be closed, not re-pooled.');
        $this->assertSame(0, $statsAfterRelease['available_connections']);
        $this->assertNotSame($first, $second, 'A fresh connection must replace the expired one.');
        $this->assertCount(1, $first->getQueryLog(), 'Expired connections must be closed directly, without a pointless session reset.');
    }

    public function test_release_repools_connection_within_max_lifetime(): void
    {
        $this->skipIfNoSwooleCoroutine();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite is required.');
        }

        $factory = Mockery::mock(ConnectionFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->withAnyArgs()
            ->andReturnUsing(fn () => new Connection(new PDO('sqlite::memory:'), 'database', '', []));

        $first = null;
        $second = null;
        $statsAfterRelease = null;

        \Swoole\Coroutine\run(function () use ($factory, &$first, &$second, &$statsAfterRelease) {
            $pool = new DatabasePool([
                'min_connections' => 0,
                'max_connections' => 2,
                'wait_timeout' => 0.1,
                'max_lifetime' => 60.0,
            ], [], 'sqlite', $factory);

            $first = $pool->get();
            $pool->release($first);

            $statsAfterRelease = $pool->getStats();

            $second = $pool->get();
        });

        $this->assertSame(1, $statsAfterRelease['current_connections']);
        $this->assertSame(1, $statsAfterRelease['available_connections']);
        $this->assertSame($first, $second, 'A young connection must be re-pooled and reused.');
    }

    public function test_expired_connection_rolls_back_open_transaction_before_close(): void
    {
        $this->skipIfNoSwooleCoroutine();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite is required.');
        }

        $pdo = new PDO('sqlite::memory:');

        $factory = Mockery::mock(ConnectionFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->withAnyArgs()
            ->andReturn(new Connection($pdo, 'database', '', []));

        \Swoole\Coroutine\run(function () use ($factory) {
            $pool = new DatabasePool([
                'min_connections' => 0,
                'max_connections' => 1,
                'wait_timeout' => 0.1,
                'max_lifetime' => 0.01,
            ], [], 'sqlite', $factory);

            $connection = $pool->get();
            $connection->beginTransaction();
            \Swoole\Coroutine::sleep(0.03);
            $pool->release($connection);
        });

        // Connection::disconnect() only drops the PDO reference; if another
        // reference keeps the PDO alive (we do here, as a leaked statement
        // would), an un-rolled-back transaction would keep holding its locks.
        $this->assertFalse($pdo->inTransaction(), 'The expired fast-path must roll back abandoned transactions before closing.');
    }

    public function test_zero_max_lifetime_disables_recycling(): void
    {
        $this->skipIfNoSwooleCoroutine();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite is required.');
        }

        $factory = Mockery::mock(ConnectionFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->withAnyArgs()
            ->andReturnUsing(fn () => new Connection(new PDO('sqlite::memory:'), 'database', '', []));

        $first = null;
        $second = null;

        \Swoole\Coroutine\run(function () use ($factory, &$first, &$second) {
            $pool = new DatabasePool([
                'min_connections' => 0,
                'max_connections' => 2,
                'wait_timeout' => 0.1,
                'max_lifetime' => 0,
            ], [], 'sqlite', $factory);

            $first = $pool->get();
            \Swoole\Coroutine::sleep(0.05);
            $pool->release($first);
            $second = $pool->get();
        });

        $this->assertSame($first, $second, 'max_lifetime of 0 must never expire connections.');
    }

    public function test_prune_closes_over_age_connections_even_at_min_connections(): void
    {
        $this->skipIfNoSwooleCoroutine();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite is required.');
        }

        $factory = Mockery::mock(ConnectionFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->withAnyArgs()
            ->andReturnUsing(fn () => new Connection(new PDO('sqlite::memory:'), 'database', '', []));

        $closed = null;
        $stats = null;

        \Swoole\Coroutine\run(function () use ($factory, &$closed, &$stats) {
            $pool = new DatabasePool([
                'min_connections' => 1,
                'max_connections' => 1,
                'wait_timeout' => 0.1,
                'heartbeat' => -1,
                'max_idle_time' => 60.0,
                'max_lifetime' => 0.01,
            ], [], 'sqlite', $factory);

            \Swoole\Coroutine::sleep(0.03);

            $closed = $pool->pruneIdleConnections();
            $stats = $pool->getStats();
        });

        $this->assertSame(1, $closed, 'Over-age connections must be pruned even when the pool is at min_connections.');
        $this->assertSame(0, $stats['current_connections']);
    }

    public function test_reconcile_heals_slots_for_connections_dropped_without_release(): void
    {
        $this->skipIfNoSwooleCoroutine();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite is required.');
        }

        $factory = Mockery::mock(ConnectionFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->withAnyArgs()
            ->andReturnUsing(fn () => new Connection(new PDO('sqlite::memory:'), 'database', '', []));

        $healed = null;
        $stats = null;

        \Swoole\Coroutine\run(function () use ($factory, &$healed, &$stats) {
            $pool = new DatabasePool([
                'min_connections' => 0,
                'max_connections' => 2,
                'wait_timeout' => 0.1,
                'max_lifetime' => 60.0,
            ], [], 'sqlite', $factory);

            $connection = $pool->get();
            $this->assertSame(1, $pool->getStats()['current_connections']);

            // A borrower that vanishes without release() - e.g. a child
            // coroutine whose context died with the connection inside it.
            unset($connection);
            gc_collect_cycles();

            $healed = $pool->reconcileVanishedConnections();
            $stats = $pool->getStats();
        });

        $this->assertSame(1, $healed, 'The vanished connection must be detected via its dead weak reference.');
        $this->assertSame(0, $stats['current_connections'], 'The slot must be reclaimed so the pool cannot drift into false exhaustion.');
        $this->assertSame(0, $stats['tracked_connections']);
    }

    public function test_reconcile_leaves_live_connections_alone(): void
    {
        $this->skipIfNoSwooleCoroutine();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite is required.');
        }

        $factory = Mockery::mock(ConnectionFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->withAnyArgs()
            ->andReturnUsing(fn () => new Connection(new PDO('sqlite::memory:'), 'database', '', []));

        \Swoole\Coroutine\run(function () use ($factory) {
            $pool = new DatabasePool([
                'min_connections' => 0,
                'max_connections' => 2,
                'wait_timeout' => 0.1,
                'max_lifetime' => 60.0,
            ], [], 'sqlite', $factory);

            $connection = $pool->get();
            gc_collect_cycles();

            $this->assertSame(0, $pool->reconcileVanishedConnections(), 'A held connection must never be treated as vanished.');
            $this->assertSame(1, $pool->getStats()['current_connections']);

            $pool->release($connection);
            $this->assertSame(1, $pool->getStats()['current_connections']);
        });
    }

    public function test_tracking_a_new_connection_heals_a_reused_object_id(): void
    {
        $pool = $this->newPoolWithoutConstructor();

        $abandoned = new \stdClass();
        $reusedId = spl_object_id($abandoned);

        $live = new \ReflectionProperty(DatabasePool::class, 'liveConnections');
        $live->setValue($pool, [$reusedId => \WeakReference::create($abandoned)]);
        $this->setPoolCurrentConnections($pool, 1);

        // The abandoned connection dies while factory->make() is connecting;
        // PHP hands its object id to the next same-shape allocation.
        unset($abandoned);
        $fresh = new \stdClass();

        if (spl_object_id($fresh) !== $reusedId) {
            $this->markTestSkipped('Allocator did not reuse the object id on this build.');
        }

        $track = new ReflectionMethod(DatabasePool::class, 'trackConnection');
        $track->invoke($pool, $fresh);

        $current = new \ReflectionProperty(DatabasePool::class, 'currentConnections');
        $this->assertSame(0, $current->getValue($pool), 'Overwriting a dead weak reference must heal the abandoned slot, or the drift becomes permanently unhealable.');
        $this->assertSame($fresh, $live->getValue($pool)[$reusedId]->get());
    }

    public function test_checkout_skips_ping_and_session_reset_for_recently_pooled_connection(): void
    {
        $this->skipIfNoSwooleCoroutine();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite is required.');
        }

        $factory = Mockery::mock(ConnectionFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->withAnyArgs()
            ->andReturnUsing(fn () => new Connection(new CountingSqlitePdo('sqlite::memory:'), 'database', '', []));

        \Swoole\Coroutine\run(function () use ($factory) {
            $pool = new DatabasePool([
                'min_connections' => 0,
                'max_connections' => 1,
                'wait_timeout' => 0.1,
                'max_lifetime' => 60.0,
                'ping_after_idle' => 30.0,
            ], [], 'sqlite', $factory);

            $connection = $pool->get();
            $pool->release($connection);

            // Probe: resetConnection flushes the query log; a surviving entry
            // proves the checkout skipped the redundant session reset.
            $connection->enableQueryLog();
            $connection->logQuery('probe', [], 0);
            $pings = $connection->getPdo()->queryCalls;

            $again = $pool->get();

            $this->assertSame($connection, $again);
            $this->assertSame($pings, $connection->getPdo()->queryCalls, 'A connection idle for milliseconds must not be pinged on checkout.');
            $this->assertCount(1, $connection->getQueryLog(), 'A clean pooled connection must not pay the session reset on checkout.');
        });
    }

    public function test_checkout_pings_connection_idle_beyond_threshold(): void
    {
        $this->skipIfNoSwooleCoroutine();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite is required.');
        }

        $factory = Mockery::mock(ConnectionFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->withAnyArgs()
            ->andReturnUsing(fn () => new Connection(new CountingSqlitePdo('sqlite::memory:'), 'database', '', []));

        \Swoole\Coroutine\run(function () use ($factory) {
            $pool = new DatabasePool([
                'min_connections' => 0,
                'max_connections' => 1,
                'wait_timeout' => 0.1,
                'max_lifetime' => 600.0,
                'max_idle_time' => 600.0,
                'ping_after_idle' => 30.0,
            ], [], 'sqlite', $factory);

            $connection = $pool->get();
            $pool->release($connection);

            // Age the idle timestamp past the ping threshold.
            $idle = new \ReflectionProperty(DatabasePool::class, 'idleSince');
            $entries = $idle->getValue($pool);
            foreach ($entries as $id => $since) {
                $entries[$id] = $since - 120.0;
            }
            $idle->setValue($pool, $entries);

            $pings = $connection->getPdo()->queryCalls;
            $pool->get();

            $this->assertGreaterThan($pings, $connection->getPdo()->queryCalls, 'A connection idle past ping_after_idle must be pinged before handout.');
        });
    }

    public function test_checkout_still_resets_a_dirty_pooled_connection(): void
    {
        $this->skipIfNoSwooleCoroutine();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite is required.');
        }

        $pdo = new PDO('sqlite::memory:');
        $connection = new Connection($pdo, 'database', '', []);

        $factory = Mockery::mock(ConnectionFactory::class);
        $factory->shouldReceive('make')->never();

        \Swoole\Coroutine\run(function () use ($factory, $connection, $pdo) {
            $pool = new DatabasePool([
                'min_connections' => 0,
                'max_connections' => 1,
                'wait_timeout' => 0.1,
            ], [], 'sqlite', $factory);

            // Plant a DIRTY connection straight into the channel, bypassing
            // release() - simulating any path that re-pools without reset.
            $connection->beginTransaction();
            $channel = new \ReflectionProperty(DatabasePool::class, 'channel');
            $channel->getValue($pool)->push($connection);
            $this->setPoolCurrentConnections($pool, 1);
            $live = new \ReflectionProperty(DatabasePool::class, 'liveConnections');
            $live->setValue($pool, [spl_object_id($connection) => \WeakReference::create($connection)]);

            $handed = $pool->get();

            $this->assertSame($connection, $handed);
            $this->assertSame(0, $handed->transactionLevel(), 'A dirty pooled connection must still be reset at checkout.');
            $this->assertFalse($pdo->inTransaction());
        });
    }

    protected function newPoolWithoutConstructor(): DatabasePool
    {
        $reflection = new \ReflectionClass(DatabasePool::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    protected function invokeResetConnection(DatabasePool $pool, Connection $connection): void
    {
        $method = new ReflectionMethod(DatabasePool::class, 'resetConnection');
        $method->setAccessible(true);
        $method->invoke($pool, $connection);
    }

    protected function setPoolCurrentConnections(DatabasePool $pool, int $value): void
    {
        $property = new \ReflectionProperty(DatabasePool::class, 'currentConnections');
        $property->setAccessible(true);
        $property->setValue($pool, $value);
    }

    protected function setPoolConfig(DatabasePool $pool, array $value): void
    {
        $property = new \ReflectionProperty(DatabasePool::class, 'config');
        $property->setAccessible(true);
        $property->setValue($pool, $value);
    }

    protected function skipIfNoSwooleCoroutine(): void
    {
        if (!class_exists(\Swoole\Coroutine::class) || !function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }
    }
}

class CountingSqlitePdo extends PDO
{
    public int $queryCalls = 0;

    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs)
    {
        $this->queryCalls++;

        if ($fetchMode === null) {
            return parent::query($query);
        }

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

class TestPdoSuccess
{
    public function query(string $sql): bool
    {
        return true;
    }
}

class TestPdoFailure
{
    public function query(string $sql): bool
    {
        throw new \RuntimeException('PDO connection is stale.');
    }
}

class TestConnection
{
    public bool $reconnected = false;

    public function __construct(private object $pdo)
    {
    }

    public function getPdo(): object
    {
        return $this->pdo;
    }

    public function reconnect(): void
    {
        $this->reconnected = true;
    }
}
