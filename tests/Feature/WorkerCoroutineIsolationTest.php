<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $this->assertCount(count($requests), $client->responses);

        foreach ($client->responses as $response) {
            $payload = json_decode($response->getContent(), true);

            $this->assertIsArray($payload);
            $this->assertSame($payload['id'], $payload['instance']);
            $this->assertSame($payload['id'], $payload['config']);
            $this->assertSame($payload['id'], $payload['session']);
        }

        $this->assertSame([true, true], $contextCleared);
    }

    /**
     * @requires extension swoole
     */
    public function test_route_injected_request_matches_helper_per_coroutine(): void
    {
        if (! class_exists(Coroutine::class) || ! function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $this->app['router']->get('/request-check', function (Request $request) {
            Coroutine::sleep(0.01);

            return response()->json([
                'injected' => [
                    'path' => $request->path(),
                    'request_id' => $request->query('request_id'),
                    'header' => $request->header('X-Test-Id'),
                ],
                'helper' => [
                    'path' => request()->path(),
                    'request_id' => request()->query('request_id'),
                    'header' => request()->header('X-Test-Id'),
                ],
            ]);
        });

        $client = new FakeClient([]);
        $worker = $this->createWorker($client);
        $worker->boot();

        $requests = [
            Request::create('/request-check?request_id=alpha', 'GET', [], [], [], ['HTTP_X_TEST_ID' => 'alpha']),
            Request::create('/request-check?request_id=bravo', 'GET', [], [], [], ['HTTP_X_TEST_ID' => 'bravo']),
        ];

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

        $this->assertCount(count($requests), $client->responses);

        foreach ($client->responses as $response) {
            $payload = json_decode($response->getContent(), true);

            $this->assertIsArray($payload);
            $this->assertSame($payload['injected']['path'], $payload['helper']['path']);
            $this->assertSame($payload['injected']['request_id'], $payload['helper']['request_id']);
            $this->assertSame($payload['injected']['header'], $payload['helper']['header']);
        }
    }

    /**
     * @requires extension swoole
     */
    public function test_constructor_injected_request_resolves_from_current_coroutine(): void
    {
        if (! class_exists(Coroutine::class) || ! function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $this->app['router']->get('/constructor-request-check', function (Request $request) {
            $probe = app(ConstructorInjectedRequestProbe::class);

            Coroutine::sleep(0.05);

            return response()->json([
                'method_request_id' => $request->query('request_id'),
                'helper_request_id' => request()->query('request_id'),
                'probe_request_id' => $probe->request->query('request_id'),
                'method_user_agent' => $request->userAgent(),
                'helper_user_agent' => request()->userAgent(),
                'probe_user_agent' => $probe->request->userAgent(),
            ]);
        });

        $client = new FakeClient([]);
        $worker = $this->createWorker($client);
        $worker->boot();

        $requests = [
            Request::create('/constructor-request-check?request_id=alpha', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'probe-alpha']),
            Request::create('/constructor-request-check?request_id=bravo', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'probe-bravo']),
        ];

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

        $this->assertCount(count($requests), $client->responses);

        foreach ($client->responses as $response) {
            $payload = json_decode($response->getContent(), true);

            $this->assertIsArray($payload);
            $this->assertSame($payload['method_request_id'], $payload['helper_request_id']);
            $this->assertSame($payload['method_request_id'], $payload['probe_request_id']);
            $this->assertSame($payload['method_user_agent'], $payload['helper_user_agent']);
            $this->assertSame($payload['method_user_agent'], $payload['probe_user_agent']);
        }
    }

    /**
     * @requires extension swoole
     */
    public function test_in_process_nested_handle_temporarily_uses_subrequest_then_restores_outer_request(): void
    {
        if (! class_exists(Coroutine::class) || ! function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $this->app['router']->get('/nested-target', function () {
            return response()->json([
                'path' => request()->path(),
                'request_id' => request()->header('X-Test-Id'),
            ]);
        });

        $this->app['router']->get('/nested-handle-check', function (Request $request) {
            $original = request();
            $subRequest = Request::create('/nested-target', 'GET', [], [], [], [
                'HTTP_X_TEST_ID' => 'inner-'.$request->header('X-Test-Id'),
            ]);

            $response = app()->handle($subRequest);
            $inner = json_decode($response->getContent(), true);

            return response()->json([
                'outer_same_request' => request() === $original,
                'outer_path' => request()->path(),
                'outer_request_id' => request()->header('X-Test-Id'),
                'inner_path' => $inner['path'] ?? null,
                'inner_request_id' => $inner['request_id'] ?? null,
            ]);
        });

        $client = new FakeClient([]);
        $worker = $this->createWorker($client);
        $worker->boot();

        $requests = [
            Request::create('/nested-handle-check', 'GET', [], [], [], ['HTTP_X_TEST_ID' => 'alpha']),
            Request::create('/nested-handle-check', 'GET', [], [], [], ['HTTP_X_TEST_ID' => 'bravo']),
        ];

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

        $this->assertCount(count($requests), $client->responses);

        foreach ($client->responses as $response) {
            $payload = json_decode($response->getContent(), true);

            $this->assertTrue($payload['outer_same_request']);
            $this->assertSame('nested-handle-check', $payload['outer_path']);
            $this->assertSame('inner-'.$payload['outer_request_id'], $payload['inner_request_id']);
            $this->assertSame('nested-target', $payload['inner_path']);
        }
    }

    /**
     * @requires extension swoole
     */
    public function test_nested_handle_preserves_api_request_shapes_without_cross_request_leaks(): void
    {
        if (! class_exists(Coroutine::class) || ! function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $this->app['router']->any('/api-shape-target', function (Request $request) {
            Coroutine::sleep(0.01);

            $body = $request->input('body', $request->json('body'));
            $base64 = (string) $request->input('base64', $request->json('base64', ''));
            $decoded = base64_decode($base64, true);

            return response()->json([
                'method' => $request->method(),
                'path' => $request->path(),
                'helper_path' => request()->path(),
                'query_request_id' => $request->query('request_id'),
                'input_request_id' => $request->input('request_id'),
                'json_request_id' => $request->json('request_id'),
                'body' => $body,
                'body_hash' => hash('sha256', (string) $body),
                'base64_hash' => $decoded === false ? null : hash('sha256', $decoded),
                'authorization' => $request->header('Authorization'),
                'content_type' => $request->headers->get('CONTENT_TYPE'),
            ]);
        });

        $this->app['router']->get('/api-shape-outer', function (Request $request) {
            $outer = request();
            $outerId = (string) $request->header('X-Test-Id');
            $cases = [
                ['GET', 'query', 'application/json'],
                ['POST', 'json', 'application/json'],
                ['POST', 'form', 'application/x-www-form-urlencoded'],
                ['PUT', 'json-put', 'application/json'],
                ['PATCH', 'form-patch', 'application/x-www-form-urlencoded'],
            ];
            $results = [];

            foreach ($cases as [$method, $kind, $contentType]) {
                $innerId = "{$outerId}-{$kind}";
                $body = "payload-{$innerId}";
                $base64 = base64_encode("binary-{$innerId}");
                $uri = '/api-shape-target?request_id='.urlencode($innerId);
                $parameters = [
                    'request_id' => $innerId,
                    'body' => $body,
                    'base64' => $base64,
                ];

                $server = [
                    'HTTP_AUTHORIZATION' => "Bearer {$innerId}",
                    'HTTP_ACCEPT' => 'application/json',
                    'CONTENT_TYPE' => $contentType,
                ];

                $subRequest = $contentType === 'application/json'
                    ? Request::create($uri, $method, [], [], [], $server, json_encode($parameters))
                    : Request::create($uri, $method, $parameters, [], [], $server);

                $response = app()->handle($subRequest);
                $payload = json_decode($response->getContent(), true);

                $results[$kind] = [
                    'restored' => request() === $outer && request()->header('X-Test-Id') === $outerId,
                    'payload' => $payload,
                    'expected' => [
                        'method' => $method,
                        'path' => 'api-shape-target',
                        'request_id' => $innerId,
                        'body_hash' => hash('sha256', $body),
                        'base64_hash' => hash('sha256', "binary-{$innerId}"),
                        'authorization' => "Bearer {$innerId}",
                    ],
                ];
            }

            return response()->json([
                'outer_request_id' => $outerId,
                'outer_path' => request()->path(),
                'results' => $results,
            ]);
        });

        $client = new FakeClient([]);
        $worker = $this->createWorker($client);
        $worker->boot();

        $requests = [];

        for ($i = 1; $i <= 10; $i++) {
            $requests[] = Request::create('/api-shape-outer', 'GET', [], [], [], ['HTTP_X_TEST_ID' => 'request-'.$i]);
        }

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

        $this->assertCount(count($requests), $client->responses);

        foreach ($client->responses as $response) {
            $payload = json_decode($response->getContent(), true);

            $this->assertSame('api-shape-outer', $payload['outer_path']);

            foreach ($payload['results'] as $result) {
                $this->assertTrue($result['restored']);

                $inner = $result['payload'];
                $expected = $result['expected'];

                $this->assertSame($expected['method'], $inner['method']);
                $this->assertSame($expected['path'], $inner['path']);
                $this->assertSame($expected['path'], $inner['helper_path']);
                $this->assertSame($expected['request_id'], $inner['input_request_id']);
                $this->assertSame($expected['body_hash'], $inner['body_hash']);
                $this->assertSame($expected['base64_hash'], $inner['base64_hash']);
                $this->assertSame($expected['authorization'], $inner['authorization']);
            }
        }
    }

    /**
     * @requires extension swoole
     */
    public function test_dirty_database_connection_is_released_before_request_scope_flush(): void
    {
        if (! class_exists(Coroutine::class) || ! function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite is required.');
        }

        $this->app['config']->set('database.default', 'sqlite');
        $this->app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
            'pool' => [
                'min_connections' => 0,
                'max_connections' => 1,
                'wait_timeout' => 0.05,
                'heartbeat' => -1,
                'max_idle_time' => 60,
            ],
        ]);
        $this->app->forgetInstance('db');
        DB::clearResolvedInstance('db');

        $this->app['router']->get('/dirty-db', function () {
            DB::connection()->getPdo();
            DB::beginTransaction();

            return response()->json([
                'status' => 'OPENED',
                'transaction_level' => DB::connection()->transactionLevel(),
            ]);
        });

        $this->app['router']->get('/clean-db', function () {
            DB::connection()->getPdo();

            return response()->json([
                'status' => 'PASS',
                'transaction_level' => DB::connection()->transactionLevel(),
            ]);
        });

        $client = new FakeClient([]);
        $worker = $this->createWorker($client);
        $worker->boot();

        \Swoole\Coroutine\run(function () use ($worker) {
            foreach (['/dirty-db', '/clean-db', '/dirty-db', '/clean-db'] as $uri) {
                $request = Request::create($uri);
                $context = new RequestContext(['request' => $request]);

                $worker->handle($request, $context);
            }
        });

        $this->assertSame([], $client->errors);
        $this->assertCount(4, $client->responses);

        $payloads = array_map(
            static fn ($response) => json_decode($response->getContent(), true),
            $client->responses
        );

        $this->assertSame('OPENED', $payloads[0]['status']);
        $this->assertSame(1, $payloads[0]['transaction_level']);
        $this->assertSame('PASS', $payloads[1]['status']);
        $this->assertSame(0, $payloads[1]['transaction_level']);
        $this->assertSame('OPENED', $payloads[2]['status']);
        $this->assertSame(1, $payloads[2]['transaction_level']);
        $this->assertSame('PASS', $payloads[3]['status']);
        $this->assertSame(0, $payloads[3]['transaction_level']);
    }

    /**
     * @requires extension swoole
     */
    public function test_request_time_container_bindings_do_not_leak_between_coroutines(): void
    {
        if (! class_exists(Coroutine::class) || ! function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $this->app['router']->get('/binding-mutation-check', function (Request $request) {
            $requestId = (string) $request->query('request_id');
            $value = "binding-{$requestId}";

            app()->bind('test.request.binding', fn () => $value);

            $beforeSleep = app('test.request.binding');
            Coroutine::sleep(0.05);
            $afterSleep = app('test.request.binding');

            return response()->json([
                'request_id' => $requestId,
                'before_sleep' => $beforeSleep,
                'after_sleep' => $afterSleep,
            ]);
        });

        $client = new FakeClient([]);
        $worker = $this->createWorker($client);
        $worker->boot();

        $requests = [
            Request::create('/binding-mutation-check?request_id=alpha', 'GET'),
            Request::create('/binding-mutation-check?request_id=bravo', 'GET'),
        ];

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

        $this->assertCount(2, $client->responses);

        foreach ($client->responses as $response) {
            $payload = json_decode($response->getContent(), true);
            $expected = "binding-{$payload['request_id']}";

            $this->assertSame($expected, $payload['before_sleep']);
            $this->assertSame($expected, $payload['after_sleep']);
        }
    }

    /**
     * @requires extension swoole
     */
    public function test_locale_and_translator_state_do_not_leak_between_coroutines(): void
    {
        if (! class_exists(Coroutine::class) || ! function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $this->app['router']->get('/locale-check', function (Request $request) {
            $locale = (string) $request->query('locale');

            app()->setLocale($locale);

            $before = [
                'app_locale' => app()->getLocale(),
                'translator_locale' => app('translator')->getLocale(),
                'config_locale' => config('app.locale'),
            ];

            Coroutine::sleep(0.05);

            return response()->json([
                'locale' => $locale,
                'before' => $before,
                'after' => [
                    'app_locale' => app()->getLocale(),
                    'translator_locale' => app('translator')->getLocale(),
                    'config_locale' => config('app.locale'),
                ],
            ]);
        });

        $client = new FakeClient([]);
        $worker = $this->createWorker($client);
        $worker->boot();

        $requests = [
            Request::create('/locale-check?locale=fr', 'GET'),
            Request::create('/locale-check?locale=ja', 'GET'),
        ];

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

        $this->assertCount(2, $client->responses);

        foreach ($client->responses as $response) {
            $payload = json_decode($response->getContent(), true);

            foreach (['before', 'after'] as $phase) {
                $this->assertSame($payload['locale'], $payload[$phase]['app_locale']);
                $this->assertSame($payload['locale'], $payload[$phase]['translator_locale']);
                $this->assertSame($payload['locale'], $payload[$phase]['config_locale']);
            }
        }
    }

    /**
     * @requires extension swoole
     */
    public function test_router_and_view_factory_are_isolated_per_coroutine(): void
    {
        if (! class_exists(Coroutine::class) || ! function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $this->app['router']->get('/router-view-check', function (Request $request) {
            $requestId = (string) $request->query('request_id');
            $view = app('view');

            $view->share('request_id', $requestId);

            Coroutine::sleep(0.05);

            return response()->json([
                'request_id' => $requestId,
                'router_request_id' => app('router')->getCurrentRequest()?->query('request_id'),
                'view_request_id' => $view->shared('request_id'),
            ]);
        });

        $client = new FakeClient([]);
        $worker = $this->createWorker($client);
        $worker->boot();

        $requests = [
            Request::create('/router-view-check?request_id=alpha', 'GET'),
            Request::create('/router-view-check?request_id=bravo', 'GET'),
        ];

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

        $this->assertCount(2, $client->responses);

        foreach ($client->responses as $response) {
            $payload = json_decode($response->getContent(), true);

            $this->assertIsArray($payload);
            $this->assertSame($payload['request_id'], $payload['router_request_id']);
            $this->assertSame($payload['request_id'], $payload['view_request_id']);
        }
    }
}

class ConstructorInjectedRequestProbe
{
    public function __construct(public Request $request)
    {
    }
}
