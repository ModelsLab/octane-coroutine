<?php

namespace Laravel\Octane;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Laravel\Octane\Swoole\Coroutine\Context;
use Swoole\Coroutine;

/**
 * Coroutine-safe application context manager.
 *
 * This class manages the current application instance in a coroutine-safe way,
 * ensuring each coroutine gets its own isolated sandbox application.
 */
class CurrentApplication
{
    /**
     * The context key for storing the application.
     */
    protected const CONTEXT_KEY = 'octane.current_app';

    /**
     * Fallback application for non-coroutine contexts.
     */
    protected static ?Application $fallbackApp = null;

    /**
     * Set the current application in coroutine context.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    public static function set(Application $app): void
    {
        // Set the app as its own 'app' instance for proper resolution
        $app->instance('app', $app);
        $app->instance(Container::class, $app);

        // Check if we're in a coroutine
        if (class_exists(Coroutine::class) && Coroutine::getCid() > 0) {
            // Store in coroutine context
            Context::set(self::CONTEXT_KEY, $app);
            Context::set('octane.app', $app); // Also set the key used by CoroutineApplication
        } else {
            // Store in static fallback for non-coroutine contexts
            static::$fallbackApp = $app;
        }

        // Clear and set facades to use this app
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    /**
     * Get the current application from context.
     *
     * @return \Illuminate\Foundation\Application|null
     */
    public static function get(): ?Application
    {
        // Check if we're in a coroutine
        if (class_exists(Coroutine::class) && Coroutine::getCid() > 0) {
            // Try the current app key first
            $app = Context::get(self::CONTEXT_KEY);
            if ($app) {
                return $app;
            }

            // Fall back to the key used by CoroutineApplication
            $app = Context::get('octane.app');
            if ($app) {
                return $app;
            }
        }

        // Fall back to static storage for non-coroutine contexts
        if (static::$fallbackApp) {
            return static::$fallbackApp;
        }

        // Last resort: try to get from Container
        try {
            $instance = Container::getInstance();
            if ($instance instanceof Application) {
                return $instance;
            }
        } catch (\Throwable $e) {
            // Container not available
        }

        return null;
    }

    /**
     * Check if we're currently running in a coroutine.
     *
     * @return bool
     */
    public static function inCoroutine(): bool
    {
        return class_exists(Coroutine::class) && Coroutine::getCid() > 0;
    }

    /**
     * Clear the current application from context.
     *
     * @return void
     */
    public static function clear(): void
    {
        if (static::inCoroutine()) {
            Context::delete(self::CONTEXT_KEY);
            Context::delete('octane.app');
        } else {
            static::$fallbackApp = null;
        }
    }
}
