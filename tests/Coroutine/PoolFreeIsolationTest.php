<?php

namespace Tests\Coroutine;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Tests\TestCase;

class PoolFreeIsolationTest extends TestCase
{
    /**
     * Helper to skip when Swoole is not available.
     */
    private function requiresSwoole(): void
    {
        if (! class_exists(Coroutine::class) || ! function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }
    }

    /**
     * Test that request-scoped bindings are isolated between concurrent coroutines.
     *
     * Each coroutine stores a unique value in 'request' scope and sleeps,
     * then verifies its value hasn't been overwritten by another coroutine.
     *
     * @requires extension swoole
     */
    public function test_request_scoped_bindings_are_isolated_between_coroutines(): void
    {
        $this->requiresSwoole();

        $results = [];

        \Swoole\Coroutine\run(function () use (&$results) {
            $baseApp = $this->app;
            $done = new Channel(3);

            for ($i = 0; $i < 3; $i++) {
                $id = "coroutine-{$i}";

                Coroutine::create(function () use ($baseApp, $id, $done) {
                    $scope = new RequestScope($baseApp);
                    $scope->set('request', "request-{$id}");
                    $scope->set('auth', "auth-{$id}");
                    $scope->set('session', "session-{$id}");

                    Context::set('octane.request_scope', $scope);

                    // Yield to let other coroutines run
                    Coroutine::sleep(0.01);

                    // After yield, verify our scope is still intact
                    $myScope = Context::get('octane.request_scope');
                    $done->push([
                        'id' => $id,
                        'request' => $myScope->get('request'),
                        'auth' => $myScope->get('auth'),
                        'session' => $myScope->get('session'),
                    ]);
                });
            }

            for ($i = 0; $i < 3; $i++) {
                $results[] = $done->pop();
            }
        });

        $this->assertCount(3, $results);

        foreach ($results as $result) {
            $id = $result['id'];
            $this->assertSame("request-{$id}", $result['request'],
                "Request binding leaked for {$id}");
            $this->assertSame("auth-{$id}", $result['auth'],
                "Auth binding leaked for {$id}");
            $this->assertSame("session-{$id}", $result['session'],
                "Session binding leaked for {$id}");
        }
    }

    /**
     * Test that process-scoped bindings (like router) are shared, not duplicated.
     *
     * @requires extension swoole
     */
    public function test_process_scoped_bindings_are_shared(): void
    {
        $this->requiresSwoole();

        $results = [];

        \Swoole\Coroutine\run(function () use (&$results) {
            $baseApp = $this->app;
            $done = new Channel(2);

            for ($i = 0; $i < 2; $i++) {
                Coroutine::create(function () use ($baseApp, $done) {
                    // Process-scoped bindings should all return the same instance
                    $router = $baseApp->make('router');
                    $done->push(spl_object_id($router));
                });
            }

            for ($i = 0; $i < 2; $i++) {
                $results[] = $done->pop();
            }
        });

        // Both coroutines should get the exact same router instance
        $this->assertCount(2, $results);
        $this->assertSame($results[0], $results[1],
            'Process-scoped binding "router" should be shared across coroutines');
    }

    /**
     * Test that config mutations in one coroutine don't leak to another.
     *
     * @requires extension swoole
     */
    public function test_config_mutations_do_not_leak_between_coroutines(): void
    {
        $this->requiresSwoole();

        $results = [];

        \Swoole\Coroutine\run(function () use (&$results) {
            $baseApp = $this->app;
            $done = new Channel(2);

            // Coroutine 1: mutates config
            Coroutine::create(function () use ($baseApp, $done) {
                $scope = new RequestScope($baseApp);
                Context::set('octane.request_scope', $scope);

                // Copy-on-write: clone config before mutation
                $scope->ensureConfigCloned();
                $config = $scope->get('config');
                $config->set('test.isolation', 'mutated-value');

                Coroutine::sleep(0.01);

                $myConfig = Context::get('octane.request_scope')->get('config');
                $done->push([
                    'coroutine' => 1,
                    'value' => $myConfig->get('test.isolation'),
                ]);
            });

            // Coroutine 2: reads config (should not see mutation)
            Coroutine::create(function () use ($baseApp, $done) {
                $scope = new RequestScope($baseApp);
                Context::set('octane.request_scope', $scope);

                Coroutine::sleep(0.02);

                // Should NOT have the mutated value since config wasn't cloned here
                $baseConfig = $baseApp->make('config');
                $done->push([
                    'coroutine' => 2,
                    'value' => $baseConfig->get('test.isolation'),
                ]);
            });

            for ($i = 0; $i < 2; $i++) {
                $results[] = $done->pop();
            }
        });

        $this->assertCount(2, $results);

        // Sort by coroutine number
        usort($results, fn ($a, $b) => $a['coroutine'] <=> $b['coroutine']);

        // Coroutine 1 should see its own mutation
        $this->assertSame('mutated-value', $results[0]['value']);

        // Coroutine 2 should NOT see coroutine 1's mutation (config was cloned COW)
        $this->assertNull($results[1]['value']);
    }

    /**
     * Test that auth state doesn't leak between coroutines.
     *
     * @requires extension swoole
     */
    public function test_auth_state_does_not_leak_between_coroutines(): void
    {
        $this->requiresSwoole();

        $results = [];

        \Swoole\Coroutine\run(function () use (&$results) {
            $baseApp = $this->app;
            $done = new Channel(2);

            Coroutine::create(function () use ($baseApp, $done) {
                $scope = new RequestScope($baseApp);
                $scope->set('auth', 'user-alice');
                $scope->set('auth.driver', 'driver-alice');
                Context::set('octane.request_scope', $scope);

                Coroutine::sleep(0.01);

                $s = Context::get('octane.request_scope');
                $done->push(['auth' => $s->get('auth'), 'driver' => $s->get('auth.driver')]);
            });

            Coroutine::create(function () use ($baseApp, $done) {
                $scope = new RequestScope($baseApp);
                $scope->set('auth', 'user-bob');
                $scope->set('auth.driver', 'driver-bob');
                Context::set('octane.request_scope', $scope);

                Coroutine::sleep(0.01);

                $s = Context::get('octane.request_scope');
                $done->push(['auth' => $s->get('auth'), 'driver' => $s->get('auth.driver')]);
            });

            for ($i = 0; $i < 2; $i++) {
                $results[] = $done->pop();
            }
        });

        $this->assertCount(2, $results);

        // Each coroutine should have its own auth state
        $auths = array_column($results, 'auth');
        $this->assertContains('user-alice', $auths);
        $this->assertContains('user-bob', $auths);

        // Verify each result is internally consistent
        foreach ($results as $result) {
            if ($result['auth'] === 'user-alice') {
                $this->assertSame('driver-alice', $result['driver']);
            } else {
                $this->assertSame('driver-bob', $result['driver']);
            }
        }
    }

    /**
     * Test that session state doesn't leak between coroutines.
     *
     * @requires extension swoole
     */
    public function test_session_state_does_not_leak_between_coroutines(): void
    {
        $this->requiresSwoole();

        $results = [];

        \Swoole\Coroutine\run(function () use (&$results) {
            $baseApp = $this->app;
            $done = new Channel(2);

            Coroutine::create(function () use ($baseApp, $done) {
                $scope = new RequestScope($baseApp);
                $scope->set('session', 'session-for-user-1');
                Context::set('octane.request_scope', $scope);

                Coroutine::sleep(0.01);

                $done->push(Context::get('octane.request_scope')->get('session'));
            });

            Coroutine::create(function () use ($baseApp, $done) {
                $scope = new RequestScope($baseApp);
                $scope->set('session', 'session-for-user-2');
                Context::set('octane.request_scope', $scope);

                Coroutine::sleep(0.01);

                $done->push(Context::get('octane.request_scope')->get('session'));
            });

            for ($i = 0; $i < 2; $i++) {
                $results[] = $done->pop();
            }
        });

        $this->assertCount(2, $results);
        $this->assertContains('session-for-user-1', $results);
        $this->assertContains('session-for-user-2', $results);
    }

    /**
     * Test that no 503 is returned regardless of concurrent request count.
     *
     * With the pool-free model there is no pool to exhaust, so any number
     * of concurrent coroutines should succeed (no 503 response).
     *
     * @requires extension swoole
     */
    public function test_no_503_regardless_of_concurrent_request_count(): void
    {
        $this->requiresSwoole();
        $this->app['config']->set('octane.swoole.pool.size', 1);

        $this->app['router']->get('/pool-free', function () {
            Coroutine::sleep(0.01);
            return response()->json(['ok' => true]);
        });

        $client = new FakeClient([]);
        $worker = $this->createWorker($client);
        $worker->boot();

        $concurrentCount = 50;

        \Swoole\Coroutine\run(function () use ($worker, $concurrentCount) {
            $done = new Channel($concurrentCount);

            for ($i = 0; $i < $concurrentCount; $i++) {
                Coroutine::create(function () use ($worker, $done) {
                    $request = Request::create('/pool-free', 'GET');
                    $context = new RequestContext(['request' => $request]);
                    $worker->handle($request, $context);
                    $done->push(true);
                });
            }

            for ($i = 0; $i < $concurrentCount; $i++) {
                $done->pop();
            }
        });

        // All requests should complete successfully -- no 503s
        $this->assertCount($concurrentCount, $client->responses);
        $this->assertEmpty($client->errors);

        foreach ($client->responses as $response) {
            $this->assertSame(200, $response->getStatusCode());
        }
    }

    /**
     * Test that RequestScope is properly cleared after each request.
     *
     * @requires extension swoole
     */
    public function test_request_scope_is_cleared_after_request(): void
    {
        $this->requiresSwoole();

        $scopeCleared = [];

        \Swoole\Coroutine\run(function () use (&$scopeCleared) {
            $baseApp = $this->app;
            $done = new Channel(2);

            for ($i = 0; $i < 2; $i++) {
                Coroutine::create(function () use ($baseApp, $done, $i) {
                    $scope = new RequestScope($baseApp);
                    $scope->set('request', "request-{$i}");
                    $scope->set('auth', "auth-{$i}");
                    Context::set('octane.request_scope', $scope);

                    // Simulate request processing
                    Coroutine::sleep(0.01);

                    // Clean up (as Worker::handle() does in finally block)
                    $scope->clear();
                    Context::clear();

                    // After cleanup, scope should be empty
                    $done->push([
                        'scope_empty' => count($scope->all()) === 0,
                        'context_empty' => ! Context::has('octane.request_scope'),
                    ]);
                });
            }

            for ($i = 0; $i < 2; $i++) {
                $scopeCleared[] = $done->pop();
            }
        });

        foreach ($scopeCleared as $result) {
            $this->assertTrue($result['scope_empty'], 'RequestScope should be empty after clear');
            $this->assertTrue($result['context_empty'], 'Context should not have request_scope after clear');
        }
    }

    /**
     * Test that CoroutineApplication.make() routes request-scoped keys through RequestScope.
     *
     * @requires extension swoole
     */
    public function test_coroutine_application_routes_request_scoped_keys_through_scope(): void
    {
        $this->requiresSwoole();

        $results = [];

        \Swoole\Coroutine\run(function () use (&$results) {
            $baseApp = $this->app;
            $proxy = new CoroutineApplication($baseApp);
            $done = new Channel(2);

            Coroutine::create(function () use ($proxy, $baseApp, $done) {
                $scope = new RequestScope($baseApp);
                $scope->set('request', 'request-from-scope-A');
                Context::set('octane.request_scope', $scope);

                // make('request') should return from RequestScope, not base app
                $done->push($proxy->make('request'));
            });

            Coroutine::create(function () use ($proxy, $baseApp, $done) {
                $scope = new RequestScope($baseApp);
                $scope->set('request', 'request-from-scope-B');
                Context::set('octane.request_scope', $scope);

                $done->push($proxy->make('request'));
            });

            for ($i = 0; $i < 2; $i++) {
                $results[] = $done->pop();
            }
        });

        $this->assertCount(2, $results);
        $this->assertContains('request-from-scope-A', $results);
        $this->assertContains('request-from-scope-B', $results);
    }

    /**
     * Test that CoroutineApplication.instance() stores request-scoped keys in RequestScope.
     *
     * @requires extension swoole
     */
    public function test_coroutine_application_instance_stores_in_scope(): void
    {
        $this->requiresSwoole();

        $result = null;

        \Swoole\Coroutine\run(function () use (&$result) {
            $baseApp = $this->app;
            $proxy = new CoroutineApplication($baseApp);

            Coroutine::create(function () use ($proxy, $baseApp, &$result) {
                $scope = new RequestScope($baseApp);
                Context::set('octane.request_scope', $scope);

                // instance() for request-scoped key should store in scope
                $proxy->instance('auth', 'stored-auth-instance');

                // Verify it's in the scope
                $result = [
                    'in_scope' => $scope->has('auth'),
                    'from_make' => $proxy->make('auth'),
                    'from_bound' => $proxy->bound('auth'),
                ];
            });
        });

        $this->assertTrue($result['in_scope']);
        $this->assertSame('stored-auth-instance', $result['from_make']);
        $this->assertTrue($result['from_bound']);
    }

    /**
     * Test full request lifecycle with pool-free Worker::handle().
     *
     * @requires extension swoole
     */
    public function test_full_request_lifecycle_with_pool_free_worker(): void
    {
        $this->requiresSwoole();

        $this->app['router']->get('/lifecycle', function (Request $request) {
            return response()->json([
                'path' => $request->path(),
                'helper_path' => app('request')->path(),
                'method' => $request->method(),
            ]);
        });

        $client = new FakeClient([]);
        $worker = $this->createWorker($client);
        $worker->boot();

        \Swoole\Coroutine\run(function () use ($worker) {
            $done = new Channel(1);

            Coroutine::create(function () use ($worker, $done) {
                $request = Request::create('/lifecycle', 'GET');
                $context = new RequestContext(['request' => $request]);
                $worker->handle($request, $context);
                $done->push(true);
            });

            $done->pop();
        });

        $this->assertCount(1, $client->responses);
        $payload = json_decode($client->responses[0]->getContent(), true);
        $this->assertSame('lifecycle', $payload['path']);
        $this->assertSame('lifecycle', $payload['helper_path']);
        $this->assertSame('GET', $payload['method']);
    }
}
