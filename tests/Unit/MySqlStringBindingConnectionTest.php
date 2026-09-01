<?php

namespace Tests\Unit;

use Illuminate\Database\Connection;
use Laravel\Octane\Swoole\Database\MySqlStringBindingConnection;
use Mockery;
use PDO;
use PHPUnit\Framework\TestCase;

class MySqlStringBindingConnectionTest extends TestCase
{
    protected function tearDown(): void
    {
        if ($container = Mockery::getContainer()) {
            $this->addToAssertionCount($container->mockery_getExpectationCount());
        }

        Mockery::close();
        parent::tearDown();
    }

    public function test_integers_are_bound_as_strings(): void
    {
        $connection = new MySqlStringBindingConnection(new \stdClass(), 'db', '', []);

        $statement = Mockery::mock(\PDOStatement::class);
        $statement->shouldReceive('bindValue')->once()->with(1, Mockery::mustBe('5'), PDO::PARAM_STR);
        $statement->shouldReceive('bindValue')->once()->with(2, Mockery::mustBe('abc'), PDO::PARAM_STR);
        $statement->shouldReceive('bindValue')->once()->with(3, Mockery::mustBe(null), PDO::PARAM_STR);

        $connection->bindValues($statement, [5, 'abc', null]);

        $named = Mockery::mock(\PDOStatement::class);
        $named->shouldReceive('bindValue')->once()->with('named', Mockery::mustBe('42'), PDO::PARAM_STR);

        $connection->bindValues($named, ['named' => 42]);
    }

    public function test_large_integers_stringify_losslessly(): void
    {
        $connection = new MySqlStringBindingConnection(new \stdClass(), 'db', '', []);

        $statement = Mockery::mock(\PDOStatement::class);
        $statement->shouldReceive('bindValue')->once()->with(1, Mockery::mustBe((string) PHP_INT_MAX), PDO::PARAM_STR);

        $connection->bindValues($statement, [PHP_INT_MAX]);
    }
}
