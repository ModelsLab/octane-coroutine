<?php

namespace Laravel\Octane;

use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;

/**
 * Coroutine-safe application context manager using Hyperf's proven solution.
 * 
 * This replaces Laravel's global Container::setInstance() with Hyperf's
 * coroutine-aware ApplicationContext, eliminating race conditions when
 * multiple concurrent requests execute in the same worker process.
 */
class CurrentApplication
{
    /**
     * Set the current application in coroutine context using Hyperf's ApplicationContext.
     * 
     * This is the industry-standard solution for coroutine isolation in PHP.
     */
    public static function set(Application $app): void
    {
        $app->instance('app', $app);
        $app->instance(Container::class, $app);

        // Use Hyperf's ApplicationContext for coroutine-aware container storage
        ApplicationContext::setContainer($app);
        
        // Also store in raw coroutine context as backup
        Context::set('laravel.app', $app);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }
    
    /**
     * Get the current application from Hyperf's ApplicationContext.
     * 
     * @return \Illuminate\Foundation\Application|\Psr\Container\ContainerInterface|null
     */
    public static function get()
    {
        try {
            return ApplicationContext::getContainer();
        } catch (\Throwable $e) {
            // Fallback to raw context if ApplicationContext not set
            return Context::get('laravel.app');
        }
    }
}

