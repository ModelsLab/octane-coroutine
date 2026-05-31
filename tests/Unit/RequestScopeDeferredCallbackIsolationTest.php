<?php

namespace Tests\Unit;

use Illuminate\Foundation\Application;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

class RequestScopeDeferredCallbackIsolationTest extends TestCase
{
    public function test_deferred_callback_collection_is_request_scoped(): void
    {
        $base = new Application(__DIR__);
        $base->scoped(DeferredCallbackCollection::class);
        $baseCollection = $base->make(DeferredCallbackCollection::class);

        $sandbox = new CoroutineApplication($base);
        $firstScope = new RequestScope($base);
        $secondScope = new RequestScope($base);

        $firstCollection = $firstScope->resolve(DeferredCallbackCollection::class, $sandbox);
        $secondCollection = $secondScope->resolve(DeferredCallbackCollection::class, $sandbox);

        $this->assertInstanceOf(DeferredCallbackCollection::class, $firstCollection);
        $this->assertInstanceOf(DeferredCallbackCollection::class, $secondCollection);
        $this->assertNotSame($baseCollection, $firstCollection);
        $this->assertNotSame($baseCollection, $secondCollection);
        $this->assertNotSame($firstCollection, $secondCollection);
    }

    public function test_coroutine_application_resolves_distinct_deferred_collections_per_coroutine(): void
    {
        if (! class_exists(Coroutine::class) || ! class_exists(Channel::class)) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $base = new Application(__DIR__);
        $base->scoped(DeferredCallbackCollection::class);
        $sandbox = new CoroutineApplication($base);
        $results = [];

        Coroutine\run(function () use ($base, $sandbox, &$results) {
            $done = new Channel(2);

            for ($i = 0; $i < 2; $i++) {
                Coroutine::create(function () use ($base, $sandbox, $done) {
                    $scope = new RequestScope($base);

                    Context::set('octane.request_scope', $scope);

                    $collection = $sandbox->make(DeferredCallbackCollection::class);

                    $done->push(spl_object_id($collection));

                    $scope->clear();
                    Context::clear();
                });
            }

            for ($i = 0; $i < 2; $i++) {
                $results[] = $done->pop();
            }
        });

        $this->assertCount(2, $results);
        $this->assertNotSame($results[0], $results[1]);
    }
}
