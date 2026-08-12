<?php

namespace Tests\Unit;

use Illuminate\Foundation\Application;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use PHPUnit\Framework\TestCase;

interface ContextualProbeContract {}

class ContextualProbeImpl implements ContextualProbeContract
{
    public function __construct(public string $tag = 'contextual') {}
}

class ContextualProbeConsumer
{
    public function __construct(public ContextualProbeContract $dep) {}
}

/**
 * Contextual bindings must survive resolution through the coroutine proxy.
 *
 * `$app->when(X)->needs(Y)->give(...)` is stored per-concrete on whichever
 * container the service provider ran against — always the base app. When the
 * proxy builds an unbound concrete itself, resolving its dependencies would
 * otherwise hop back to the base app with an empty build stack, losing the
 * context. For an interface only ever satisfied contextually that is fatal.
 *
 * Passport wires its controllers this way, and on 2026-08-12 it took production
 * down: GET /oauth/authorize returned 500 with "Target
 * [Illuminate\Contracts\Auth\StatefulGuard] is not instantiable."
 */
class CoroutineApplicationContextualTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::clear();
        parent::tearDown();
    }

    public function test_contextual_binding_registered_on_the_base_app_is_honoured()
    {
        $base = new Application;
        $base->when(ContextualProbeConsumer::class)
            ->needs(ContextualProbeContract::class)
            ->give(fn () => new ContextualProbeImpl('from-contextual'));

        $proxy = new CoroutineApplication($base);
        Context::set('octane.request_scope', new RequestScope($base));

        $consumer = $proxy->make(ContextualProbeConsumer::class);

        $this->assertInstanceOf(ContextualProbeConsumer::class, $consumer);
        $this->assertSame('from-contextual', $consumer->dep->tag,
            'Contextual binding from the base app was lost when building through the proxy.');
    }

    public function test_an_interface_with_no_binding_at_all_still_reports_clearly()
    {
        $base = new Application;
        $proxy = new CoroutineApplication($base);
        Context::set('octane.request_scope', new RequestScope($base));

        // No contextual binding registered: this must still fail, and fail as a
        // resolution error rather than silently returning something wrong.
        $this->expectExceptionMessageMatches('/not instantiable|Unresolvable|Target/');
        $proxy->make(ContextualProbeConsumer::class);
    }
}
