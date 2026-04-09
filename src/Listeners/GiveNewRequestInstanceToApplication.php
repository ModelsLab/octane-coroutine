<?php

namespace Laravel\Octane\Listeners;

use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;

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
            return;
        }

        $event->app->instance('request', $event->request);
    }
}
