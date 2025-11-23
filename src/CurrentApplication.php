<?php

namespace Laravel\Octane;

use Swoole\Coroutine;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;

class CurrentApplication
{
    /**
     * Set the current application in coroutine context (not global static!).
     * 
     * CRITICAL FIX: Store in Swoole\Coroutine context to prevent race conditions
     * where concurrent requests overwrite each other's container instances.
     */
    public static function set(Application $app): void
    {
        $app->instance('app', $app);
        $app->instance(Container::class, $app);

        // CRITICAL FIX: Store in coroutine context instead of global static
        // This prevents race conditions in concurrent request handling
        if (extension_loaded('swoole') && Coroutine::getCid() > 0) {
            $context = Coroutine::getContext();
            $context['app'] = $app;
        } else {
            // Fallback for non-coroutine environments (e.g., testing, CLI)
            Container::setInstance($app);
        }

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }
    
    /**
     * Get the current application from coroutine context.
     */
    public static function get(): ?Application
    {
        if (extension_loaded('swoole') && Coroutine::getCid() > 0) {
            $context = Coroutine::getContext();
            return $context['app'] ?? null;
        }
        
        return Container::getInstance();
    }
}
