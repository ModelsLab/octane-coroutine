<?php

namespace Tests\Unit;

use Closure;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Queue as BaseQueue;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use Laravel\Octane\Swoole\Database\DatabaseManager;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * Pooled connections come straight from ConnectionFactory::make(), which
 * returns a bare Connection. Illuminate's own DatabaseManager::configure()
 * is what normally attaches the event dispatcher, the transactions manager
 * and the reconnector. The pool bypasses that, so without deliberate wiring
 * every pooled connection loses DB::listen() and throws from afterCommit().
 */
class DatabasePoolConnectionConfigurationTest extends TestCase
{
    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
    }

    protected function tearDown(): void
    {
        Context::clear();

        if ($this->previousContainer !== null) {
            Container::setInstance($this->previousContainer);
        }

        parent::tearDown();
    }

    public function test_pooled_connections_fire_query_executed_events(): void
    {
        $this->skipIfUnsupported();

        $base = $this->baseApplication();
        $manager = $this->databaseManager($base);

        $queries = [];

        $base->make('events')->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$queries) {
            $queries[] = $event->sql;
        });

        $this->runRequest($base, function () use ($manager) {
            $manager->connection('sqlite')->select('select 1');
        });

        $this->assertSame(
            ['select 1'],
            $queries,
            'DB::listen() must fire for queries run on a pooled connection.'
        );
    }

    public function test_pooled_connections_fire_transaction_events(): void
    {
        $this->skipIfUnsupported();

        $base = $this->baseApplication();
        $manager = $this->databaseManager($base);

        $committed = 0;

        $base->make('events')->listen(TransactionCommitted::class, function () use (&$committed) {
            $committed++;
        });

        $this->runRequest($base, function () use ($manager) {
            $manager->connection('sqlite')->transaction(fn () => null);
        });

        $this->assertSame(1, $committed, 'TransactionCommitted must fire on a pooled connection.');
    }

    public function test_after_commit_callbacks_run_on_pooled_connections(): void
    {
        $this->skipIfUnsupported();

        $base = $this->baseApplication();
        $manager = $this->databaseManager($base);

        $ran = false;
        $ranInline = null;
        $failure = null;

        $this->runRequest($base, function () use ($manager, &$ran, &$ranInline, &$failure) {
            try {
                $connection = $manager->connection('sqlite');

                $connection->transaction(function () use ($connection, &$ran, &$ranInline) {
                    $connection->afterCommit(function () use (&$ran) {
                        $ran = true;
                    });

                    // The callback must wait for the commit, not run inline.
                    $ranInline = $ran;
                });
            } catch (\Throwable $e) {
                $failure = $e;
            }
        });

        $this->assertNull(
            $failure,
            'afterCommit() must not throw on a pooled connection. Got: '.($failure?->getMessage() ?? '')
        );
        $this->assertFalse($ranInline, 'The afterCommit callback must not run inside the transaction.');
        $this->assertTrue($ran, 'The afterCommit callback must run once the transaction commits.');
    }

    public function test_after_rollback_callbacks_run_on_pooled_connections(): void
    {
        $this->skipIfUnsupported();

        $base = $this->baseApplication();
        $manager = $this->databaseManager($base);

        $ran = false;
        $failure = null;

        $this->runRequest($base, function () use ($manager, &$ran, &$failure) {
            try {
                $connection = $manager->connection('sqlite');

                $connection->beginTransaction();
                $connection->afterRollBack(function () use (&$ran) {
                    $ran = true;
                });
                $connection->rollBack();
            } catch (\Throwable $e) {
                $failure = $e;
            }
        });

        $this->assertNull(
            $failure,
            'afterRollBack() must not throw on a pooled connection. Got: '.($failure?->getMessage() ?? '')
        );
        $this->assertTrue($ran, 'The afterRollBack callback must run once the transaction rolls back.');
    }

    /**
     * DatabaseTransactionsManager keys every record by connection *name*, and
     * addCallback() attaches to the last pending transaction regardless of
     * connection. One shared manager across concurrent coroutines therefore
     * runs one request's callbacks when an unrelated request commits.
     */
    public function test_after_commit_callbacks_do_not_leak_across_coroutines(): void
    {
        $this->skipIfUnsupported();

        $base = $this->baseApplication();
        $manager = $this->databaseManager($base);
        $this->installSandbox($base);

        $firstRan = false;
        $ranBeforeItsOwnCommit = null;
        $failure = null;

        Coroutine\run(function () use ($base, $manager, &$firstRan, &$ranBeforeItsOwnCommit, &$failure) {
            $firstBegan = new Channel(1);
            $secondCommitted = new Channel(1);

            Coroutine::create(function () use (
                $base, $manager, $firstBegan, $secondCommitted,
                &$firstRan, &$ranBeforeItsOwnCommit, &$failure
            ) {
                try {
                    Context::set('octane.request_scope', new RequestScope($base));

                    $connection = $manager->connection('sqlite');
                    $connection->beginTransaction();
                    $connection->afterCommit(function () use (&$firstRan) {
                        $firstRan = true;
                    });

                    $firstBegan->push(true);
                    $secondCommitted->pop();

                    // The second coroutine has now committed its own,
                    // unrelated transaction. Ours is still open.
                    $ranBeforeItsOwnCommit = $firstRan;

                    $connection->commit();
                } catch (\Throwable $e) {
                    $failure = $e;
                    $firstBegan->push(true);
                }
            });

            Coroutine::create(function () use ($base, $manager, $firstBegan, $secondCommitted, &$failure) {
                try {
                    $firstBegan->pop();

                    Context::set('octane.request_scope', new RequestScope($base));

                    $connection = $manager->connection('sqlite');
                    $connection->beginTransaction();
                    $connection->commit();
                } catch (\Throwable $e) {
                    $failure = $e;
                } finally {
                    $secondCommitted->push(true);
                }
            });
        });

        $this->assertNull($failure, 'Neither coroutine may fail. Got: '.($failure?->getMessage() ?? ''));
        $this->assertFalse(
            $ranBeforeItsOwnCommit,
            "A coroutine's afterCommit callback ran when a different coroutine committed."
        );
        $this->assertTrue($firstRan, 'The callback must still run when its own transaction commits.');
    }

    /**
     * Queue::enqueueUsing() reads the CONTAINER's 'db.transactions', not the
     * connection's. The connection and the container must therefore resolve
     * the same object, or a job dispatched with afterCommit() fires
     * immediately while the transaction is still open. This is why the
     * manager is container-scoped rather than private to each connection.
     */
    public function test_after_commit_jobs_wait_for_the_pooled_connection_to_commit(): void
    {
        $this->skipIfUnsupported();

        $base = $this->baseApplication();
        $manager = $this->databaseManager($base);

        $pushedDuringTransaction = null;
        $pushedAfterCommit = null;

        $this->runRequest($base, function () use ($manager, &$pushedDuringTransaction, &$pushedAfterCommit) {
            $connection = $manager->connection('sqlite');

            $queue = new RecordingQueue;
            $queue->setContainer(Container::getInstance());

            $connection->beginTransaction();
            $queue->push(new AfterCommitJob);

            $pushedDuringTransaction = count($queue->pushed);

            $connection->commit();

            $pushedAfterCommit = count($queue->pushed);
        });

        $this->assertSame(
            0,
            $pushedDuringTransaction,
            'A job dispatched with afterCommit() must not be pushed while the transaction is open.'
        );
        $this->assertSame(
            1,
            $pushedAfterCommit,
            'The job must be pushed once the pooled connection commits.'
        );
    }

    public function test_after_commit_jobs_are_not_released_by_another_coroutines_commit(): void
    {
        $this->skipIfUnsupported();

        $base = $this->baseApplication();
        $manager = $this->databaseManager($base);
        $this->installSandbox($base);

        $queue = new RecordingQueue;
        $queue->setContainer(Container::getInstance());

        $pushedAfterOtherCommit = null;
        $pushedAfterOwnCommit = null;
        $failure = null;

        Coroutine\run(function () use (
            $base, $manager, $queue,
            &$pushedAfterOtherCommit, &$pushedAfterOwnCommit, &$failure
        ) {
            $firstQueued = new Channel(1);
            $secondCommitted = new Channel(1);

            Coroutine::create(function () use (
                $base, $manager, $queue, $firstQueued, $secondCommitted,
                &$pushedAfterOtherCommit, &$pushedAfterOwnCommit, &$failure
            ) {
                try {
                    Context::set('octane.request_scope', new RequestScope($base));

                    $connection = $manager->connection('sqlite');
                    $connection->beginTransaction();
                    $queue->push(new AfterCommitJob);

                    $firstQueued->push(true);
                    $secondCommitted->pop();

                    $pushedAfterOtherCommit = count($queue->pushed);

                    $connection->commit();

                    $pushedAfterOwnCommit = count($queue->pushed);
                } catch (\Throwable $e) {
                    $failure = $e;
                    $firstQueued->push(true);
                }
            });

            Coroutine::create(function () use ($base, $manager, $firstQueued, $secondCommitted, &$failure) {
                try {
                    $firstQueued->pop();

                    Context::set('octane.request_scope', new RequestScope($base));

                    $connection = $manager->connection('sqlite');
                    $connection->beginTransaction();
                    $connection->commit();
                } catch (\Throwable $e) {
                    $failure = $e;
                } finally {
                    $secondCommitted->push(true);
                }
            });
        });

        $this->assertNull($failure, 'Neither coroutine may fail. Got: '.($failure?->getMessage() ?? ''));
        $this->assertSame(
            0,
            $pushedAfterOtherCommit,
            "A different coroutine's commit released this coroutine's afterCommit job."
        );
        $this->assertSame(1, $pushedAfterOwnCommit, 'The job must be pushed on its own commit.');
    }

    public function test_releasing_a_connection_clears_the_transaction_manager(): void
    {
        $this->skipIfUnsupported();

        $base = $this->baseApplication();
        $manager = $this->databaseManager($base);

        $connection = null;
        $managerWhileBorrowed = null;

        $this->runRequest($base, function () use ($manager, &$connection, &$managerWhileBorrowed) {
            $connection = $manager->connection('sqlite');
            $managerWhileBorrowed = $this->transactionManagerOf($connection);

            $manager->releaseConnections();
        });

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertNotNull(
            $managerWhileBorrowed,
            'A borrowed connection must carry the current request\'s transactions manager.'
        );
        $this->assertNull(
            $this->transactionManagerOf($connection),
            'A released connection must not keep the finished request\'s transactions manager.'
        );
    }

    public function test_a_reused_connection_is_rewired_for_the_next_request(): void
    {
        $this->skipIfUnsupported();

        $base = $this->baseApplication();
        $manager = $this->databaseManager($base);

        $connections = [];
        $managers = [];
        $dispatchers = [];

        foreach ([0, 1] as $ignored) {
            $this->runRequest($base, function () use ($manager, &$connections, &$managers, &$dispatchers) {
                $connection = $manager->connection('sqlite');

                $connections[] = $connection;
                $managers[] = $this->transactionManagerOf($connection);
                $dispatchers[] = $connection->getEventDispatcher();

                $manager->releaseConnections();
            });
        }

        // The pool must hand back the same physical connection...
        $this->assertSame($connections[0], $connections[1]);

        // ...still wired for events on the second borrow, not just the first.
        $this->assertNotNull($dispatchers[0]);
        $this->assertNotNull($dispatchers[1]);

        // ...but wired to the current request's transactions manager, never a
        // stale one belonging to a request that already finished.
        $this->assertNotNull($managers[0]);
        $this->assertNotNull($managers[1]);
        $this->assertNotSame($managers[0], $managers[1]);
    }

    private function baseApplication(): Application
    {
        $app = new Application(__DIR__);

        $app->instance('config', new ConfigRepository([
            'database' => [
                'default' => 'sqlite',
                'connections' => [
                    'sqlite' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                        'prefix' => '',
                        'pool' => [
                            'min_connections' => 0,
                            'max_connections' => 4,
                            'wait_timeout' => 1.0,
                            'release_timeout' => 1.0,
                            'heartbeat' => -1,
                            'max_idle_time' => 60.0,
                        ],
                    ],
                ],
            ],
        ]));

        $app->singleton('events', fn ($app) => new Dispatcher($app));
        $app->singleton('db.transactions', fn () => new DatabaseTransactionsManager);
        $app->singleton('db.factory', fn ($app) => new ConnectionFactory($app));

        return $app;
    }

    private function databaseManager(Application $base): DatabaseManager
    {
        return new DatabaseManager($base, $base->make('db.factory'));
    }

    /**
     * Mirror how Worker.php wires a coroutine request: one shared proxy
     * container, one RequestScope per coroutine.
     */
    private function installSandbox(Application $base): void
    {
        Container::setInstance(new CoroutineApplication($base));
    }

    private function runRequest(Application $base, Closure $callback): void
    {
        $this->installSandbox($base);

        Coroutine\run(function () use ($base, $callback) {
            Context::set('octane.request_scope', new RequestScope($base));

            $callback();
        });
    }

    private function transactionManagerOf(object $connection): ?DatabaseTransactionsManager
    {
        $property = new \ReflectionProperty(Connection::class, 'transactionsManager');

        return $property->getValue($connection);
    }

    private function skipIfUnsupported(): void
    {
        if (! class_exists(Coroutine::class) || ! function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite is required.');
        }
    }
}

/**
 * A queue that records pushes instead of performing them, so a test can see
 * exactly when enqueueUsing() released a job.
 */
class RecordingQueue extends BaseQueue
{
    public array $pushed = [];

    public function push($job, $data = '', $queue = null)
    {
        return $this->enqueueUsing($job, '', $queue, null, function () use ($job) {
            $this->pushed[] = $job;

            return 'recorded-job-id';
        });
    }
}

class AfterCommitJob
{
    public bool $afterCommit = true;
}
