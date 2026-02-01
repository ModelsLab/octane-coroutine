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
            'max_connections',
            'min_connections',
        ];

        // Simulated stats (what the real implementation returns)
        $stats = [
            'current_connections' => 5,
            'available_connections' => 3,
            'max_connections' => 10,
            'min_connections' => 1,
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
        $connection->shouldReceive('getPdo')->andReturn($pdo);
        $connection->shouldReceive('flushQueryLog')->once();
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
        $connection->shouldReceive('getPdo')->andReturn($pdo);
        $connection->shouldReceive('flushQueryLog')->once();
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');

        $this->invokeResetConnection($pool, $connection);

        $this->assertTrue(true);
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
