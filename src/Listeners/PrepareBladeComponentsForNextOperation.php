<?php

namespace Laravel\Octane\Listeners;

use Illuminate\View\Component;

class PrepareBladeComponentsForNextOperation
{
    /**
     * Handle the event.
     *
     * Livewire lazy placeholders (and other inline Blade components) cache
     * `__components::…` view names in a static property. Octane resets the
     * view finder between operations, so a cache hit can occur without the
     * `__components` namespace being registered again.
     *
     * @param  mixed  $event
     */
    public function handle($event): void
    {
        Component::flushCache();
        Component::forgetFactory();

        if (! $event->sandbox->bound('view')) {
            return;
        }

        $event->sandbox->make('view')->addNamespace(
            '__components',
            $event->sandbox->make('config')->get('view.compiled'),
        );
    }
}
