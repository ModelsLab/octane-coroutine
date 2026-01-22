<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Tests\TestCase;

class WorkerCoroutineIsolationTest extends TestCase
{
    /**
     * @requires extension swoole
     */
    public function test_concurrent_requests_do_not_leak_state(): void
    {
        if (! class_exists(Coroutine::class) || ! function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $this->app['router']->get('/leak-check', function (Request $request) {
            $requestId = (string) $request->header('X-Test-Id', '');

            app()->instance('test.instance', $requestId);
            config(['test.value' => $requestId]);
            $session = session();
            $session->setId($requestId);
            $session->start();
            $session->put('test.session', $requestId);
            $session->save();

            Coroutine::sleep(0.05);

            return response()->json([
                'id' => $requestId,
                'instance' => app('test.instance'),
                'config' => config('test.value'),
                'session' => $session->get('test.session'),
            ]);
        });

        $client = new FakeClient([]);
        $worker = $this->createWorker($client);
        $worker->boot();

        $requests = [
            Request::create('/leak-check', 'GET', [], [], [], ['HTTP_X_TEST_ID' => 'alpha']),
            Request::create('/leak-check', 'GET', [], [], [], ['HTTP_X_TEST_ID' => 'bravo']),
        ];

        $contextCleared = [];

        \Swoole\Coroutine\run(function () use ($worker, $requests, &$contextCleared) {
            $done = new Channel(count($requests));
            $cleared = new Channel(count($requests));

            foreach ($requests as $request) {
                Coroutine::create(function () use ($worker, $request, $done, $cleared) {
                    $context = new RequestContext(['request' => $request]);
                    $worker->handle($request, $context);
                    $done->push(true);
                    $cleared->push(count(Context::all()) === 0);
                });
            }

            for ($i = 0; $i < count($requests); $i++) {
                $done->pop();
                $contextCleared[] = (bool) $cleared->pop();
            }
        });

        $this->assertCount(2, $client->responses);

        foreach ($client->responses as $response) {
            $payload = json_decode($response->getContent(), true);

            $this->assertIsArray($payload);
            $this->assertSame($payload['id'], $payload['instance']);
            $this->assertSame($payload['id'], $payload['config']);
            $this->assertSame($payload['id'], $payload['session']);
        }

        $this->assertSame([true, true], $contextCleared);
    }
}
