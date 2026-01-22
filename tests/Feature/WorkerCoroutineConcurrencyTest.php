<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Tests\TestCase;

class WorkerCoroutineConcurrencyTest extends TestCase
{
    /**
     * @requires extension swoole
     */
    public function test_requests_run_concurrently_with_coroutine_sleep(): void
    {
        if (! class_exists(Coroutine::class) || ! function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $this->app['router']->get('/sleep', function () {
            Coroutine::sleep(0.2);
            return response()->json(['ok' => true]);
        });

        $client = new FakeClient([]);
        $worker = $this->createWorker($client);
        $worker->boot();

        $requests = [];
        for ($i = 0; $i < 10; $i++) {
            $requests[] = Request::create('/sleep', 'GET');
        }

        $startedAt = microtime(true);

        \Swoole\Coroutine\run(function () use ($worker, $requests) {
            $done = new Channel(count($requests));

            foreach ($requests as $request) {
                Coroutine::create(function () use ($worker, $request, $done) {
                    $context = new RequestContext(['request' => $request]);
                    $worker->handle($request, $context);
                    $done->push(true);
                });
            }

            for ($i = 0; $i < count($requests); $i++) {
                $done->pop();
            }
        });

        $elapsed = microtime(true) - $startedAt;

        $this->assertLessThan(1.5, $elapsed, 'Requests did not run concurrently.');
        $this->assertCount(10, $client->responses);
    }
}
