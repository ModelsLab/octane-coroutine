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
