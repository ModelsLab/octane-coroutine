<?php

namespace Tests\Feature;

use ArrayObject;
use Illuminate\Http\Request;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Database\DatabasePool;
use Laravel\Octane\Testing\Fakes\FakeClient;
use stdClass;
use Swoole\Coroutine;
use Tests\TestCase;

class WorkerPooledConnectionReleaseOrderTest extends TestCase
{
    /**
     * Statements a request leaves behind (in reference cycles, unfinished
     * generators, etc.) must be destroyed BEFORE the request's pooled
     * connection re-enters the pool. Destroying them afterwards can happen
     * while another coroutine is mid-query on the same connection, and
     * mysqlnd then skips COM_STMT_CLOSE, leaking the server-side prepared
     * statement permanently.
     *
     * @requires extension swoole
     */
    public function test_cycle_garbage_is_collected_before_pooled_connections_are_released(): void
    {
        if (! class_exists(Coroutine::class) || ! function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $events = new ArrayObject();

        $this->app['router']->get('/pool-release-order', function () use ($events) {
            Context::set('db.connection.probe', new stdClass());
            Context::set('db.connection.probe.pool', new ReleaseOrderRecordingPool($events));

            $probe = new ReleaseOrderDestructProbe($events);
            $probe->self = $probe;
            unset($probe);

            return response()->json(['ok' => true]);
        });

        $client = new FakeClient([]);
        $worker = $this->createWorker($client);
        $worker->boot();

        \Swoole\Coroutine\run(function () use ($worker) {
            Coroutine::create(function () use ($worker) {
                $request = Request::create('/pool-release-order', 'GET');
                $worker->handle($request, new RequestContext(['request' => $request]));
            });
        });

        $this->assertSame(
            ['destruct', 'release'],
            iterator_to_array($events),
            'Request garbage must be destroyed (destruct) before the pooled connection is released (release).'
        );
    }
}

class ReleaseOrderRecordingPool extends DatabasePool
{
    public function __construct(protected ArrayObject $events)
    {
    }

    public function release($connection): void
    {
        $this->events[] = 'release';
    }

    public function pruneIdleConnections(?float $now = null): int
    {
        return 0;
    }
}

class ReleaseOrderDestructProbe
{
    public ?self $self = null;

    public function __construct(protected ArrayObject $events)
    {
    }

    public function __destruct()
    {
        $this->events[] = 'destruct';
    }
}
