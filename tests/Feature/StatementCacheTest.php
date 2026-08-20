<?php

namespace Tests\Feature;

use Laravel\Octane\Swoole\Database\MySqlStringBindingConnection;
use Mockery;
use PDO;
use PDOStatement;
use Tests\TestCase;

class PrepareCountingPdo extends PDO
{
    public int $prepareCalls = 0;

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepareCalls++;

        return parent::prepare($query, $options);
    }
}

class StatementCacheTest extends TestCase
{
    protected function makeConnection(?PrepareCountingPdo &$pdo = null): MySqlStringBindingConnection
    {
        $pdo = new PrepareCountingPdo('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('create table items (id integer primary key autoincrement, name text, qty integer)');

        return new MySqlStringBindingConnection($pdo, 'db', '', ['driver' => 'mysql']);
    }

    public function test_repeated_selects_prepare_once_and_stay_correct(): void
    {
        $connection = $this->makeConnection($pdo);
        $connection->insert('insert into items (name, qty) values (?, ?)', ['alpha', 1]);
        $connection->insert('insert into items (name, qty) values (?, ?)', ['beta', 2]);

        $base = $pdo->prepareCalls;

        $first = $connection->select('select name, qty from items where qty >= ? order by id', [1]);
        $second = $connection->select('select name, qty from items where qty >= ? order by id', [2]);

        $this->assertSame($base + 1, $pdo->prepareCalls, 'The second select must reuse the cached statement.');
        $this->assertCount(2, $first);
        $this->assertCount(1, $second);
        $this->assertSame('beta', $second[0]->name);
    }

    public function test_cached_selects_see_fresh_data(): void
    {
        $connection = $this->makeConnection($pdo);
        $connection->insert('insert into items (name, qty) values (?, ?)', ['alpha', 1]);

        $this->assertCount(1, $connection->select('select * from items', []));

        $connection->insert('insert into items (name, qty) values (?, ?)', ['beta', 2]);

        $this->assertCount(2, $connection->select('select * from items', []), 'A cached statement must never serve stale results.');
    }

    public function test_writes_reuse_the_cached_statement_with_new_bindings(): void
    {
        $connection = $this->makeConnection($pdo);
        $base = $pdo->prepareCalls;

        $connection->insert('insert into items (name, qty) values (?, ?)', ['alpha', 1]);
        $connection->insert('insert into items (name, qty) values (?, ?)', ['beta', 2]);
        $connection->insert('insert into items (name, qty) values (?, ?)', ['gamma', 3]);

        $this->assertSame($base + 1, $pdo->prepareCalls);
        $this->assertSame(3, (int) $connection->selectOne('select count(*) as c from items')->c);

        $affected = $connection->update('update items set qty = qty + 1 where qty >= ?', [2]);
        $this->assertSame(2, $affected);

        $affected = $connection->update('update items set qty = qty + 1 where qty >= ?', [100]);
        $this->assertSame(0, $affected, 'rowCount must be per-execution, never remembered from the cached statement.');
    }

    public function test_last_insert_id_is_per_execution_on_a_cached_statement(): void
    {
        $connection = $this->makeConnection($pdo);

        $connection->insert('insert into items (name, qty) values (?, ?)', ['alpha', 1]);
        $this->assertSame('1', (string) $connection->getLastInsertId());

        $connection->insert('insert into items (name, qty) values (?, ?)', ['beta', 2]);
        $this->assertSame('2', (string) $connection->getLastInsertId(), 'lastInsertId must track every execution, not the first prepare.');
    }

    public function test_lru_evicts_oldest_statement_at_the_cap(): void
    {
        config(['octane.mysql_statement_cache_size' => 2]);

        $connection = $this->makeConnection($pdo);
        $base = $pdo->prepareCalls;

        $connection->select('select 1 as a', []);
        $connection->select('select 2 as a', []);
        $connection->select('select 3 as a', []); // evicts "select 1"
        $this->assertSame($base + 3, $pdo->prepareCalls);

        $connection->select('select 3 as a', []); // hit
        $this->assertSame($base + 3, $pdo->prepareCalls);

        $connection->select('select 1 as a', []); // evicted -> fresh prepare
        $this->assertSame($base + 4, $pdo->prepareCalls);
    }

    public function test_swapping_the_pdo_flushes_the_cache(): void
    {
        $connection = $this->makeConnection($pdo);

        $connection->select('select 1 as a', []);
        $before = $pdo->prepareCalls;

        $connection->setPdo($pdo);

        $connection->select('select 1 as a', []);
        $this->assertSame($before + 1, $pdo->prepareCalls, 'A PDO swap must invalidate every cached statement.');
    }

    public function test_read_and_write_sessions_cache_separately(): void
    {
        $connection = $this->makeConnection($pdo);

        $readPdo = new PrepareCountingPdo('sqlite::memory:');
        $readPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $readPdo->exec('create table items (id integer primary key, name text, qty integer)');
        $connection->setReadPdo($readPdo);

        $connection->select('select 1 as a', [], true);
        $connection->select('select 1 as a', [], false);

        $this->assertSame(1, $readPdo->prepareCalls);
        $this->assertGreaterThanOrEqual(1, $pdo->prepareCalls);
    }

    public function test_disabled_cache_prepares_every_query(): void
    {
        config(['octane.mysql_statement_cache' => false]);

        $connection = $this->makeConnection($pdo);
        $base = $pdo->prepareCalls;

        $connection->select('select 1 as a', []);
        $connection->select('select 1 as a', []);

        $this->assertSame($base + 2, $pdo->prepareCalls);
    }

    public function test_disabling_string_bindings_keeps_the_cache_and_vice_versa(): void
    {
        config(['octane.mysql_string_bindings' => false]);

        $connection = $this->makeConnection($pdo);
        $base = $pdo->prepareCalls;

        $connection->select('select 1 as a', []);
        $connection->select('select 1 as a', []);

        $this->assertSame($base + 1, $pdo->prepareCalls, 'The statement cache must survive the string-bindings hatch.');
    }

    public function test_server_statement_cap_1461_flushes_and_retries_once(): void
    {
        $capError = new \PDOException('SQLSTATE[42000]: 1461 Can\'t create more than max_prepared_stmt_count statements');
        $capError->errorInfo = ['42000', 1461, 'max_prepared_stmt_count'];

        $goodStatement = Mockery::mock(\PDOStatement::class);
        $goodStatement->shouldReceive('setFetchMode');
        $goodStatement->shouldReceive('bindValue')->andReturn(true);
        $goodStatement->shouldReceive('execute')->once()->andReturn(true);
        $goodStatement->shouldReceive('fetchAll')->andReturn([['a' => 1]]);
        $goodStatement->shouldReceive('closeCursor');

        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('prepare')->twice()->andReturnUsing(
            function () use ($capError) { throw $capError; },
            fn () => $goodStatement
        );

        $connection = new MySqlStringBindingConnection($pdo, 'db', '', ['driver' => 'mysql']);

        $rows = $connection->select('select a from t', []);

        $this->assertSame([['a' => 1]], $rows);
    }

    public function test_error_1615_evicts_and_reprepares_once(): void
    {
        $goodStatement = Mockery::mock(PDOStatement::class);
        $goodStatement->shouldReceive('execute')->once()->andReturn(true);
        $goodStatement->shouldReceive('setFetchMode');
        $goodStatement->shouldReceive('fetchAll')->andReturn([['a' => 1]]);
        $goodStatement->shouldReceive('closeCursor');
        $goodStatement->shouldReceive('bindValue')->andReturn(true);

        $needsReprepare = new \PDOException('SQLSTATE[HY000]: General error: 1615 Prepared statement needs to be re-prepared');
        $needsReprepare->errorInfo = ['HY000', 1615, 'Prepared statement needs to be re-prepared'];

        $staleStatement = Mockery::mock(PDOStatement::class);
        $staleStatement->shouldReceive('setFetchMode');
        $staleStatement->shouldReceive('closeCursor');
        $staleStatement->shouldReceive('bindValue')->andReturn(true);
        $staleStatement->shouldReceive('execute')->once()->andThrow($needsReprepare);

        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('prepare')->twice()->andReturn($staleStatement, $goodStatement);

        $connection = new MySqlStringBindingConnection($pdo, 'db', '', ['driver' => 'mysql']);

        $rows = $connection->select('select a from t where x = ?', [5]);

        $this->assertSame([['a' => 1]], $rows);
    }
}
