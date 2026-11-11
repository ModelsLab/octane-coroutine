<?php

namespace Laravel\Octane\Listeners;

use Illuminate\Http\Request;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\WorkerRequestBinding;

class GiveNewRequestInstanceToApplication
{
    /**
     * Handle the event.
     *
     * @param  mixed  $event
     */
    public function handle($event): void
    {
        $event->sandbox->instance('request', $event->request);

        if (Context::inCoroutine() || $event->sandbox instanceof CoroutineApplication) {
            // Do not pin Request on the shared worker app (race under concurrency).
            // Bind a Context-aware factory so worker-container resolution still works.
            WorkerRequestBinding::install($event->app);

            return;
        }

        $event->app->instance('request', $event->request);
    }
}
