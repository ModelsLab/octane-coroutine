<?php

namespace Laravel\Octane\Swoole\Coroutine;

use Illuminate\Foundation\Application;
use Illuminate\Container\Container;
use Swoole\Coroutine;

class CoroutineApplication extends Application
{
    /**
     * The base application instance.
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $baseApp;

    /**
     * Create a new coroutine application instance.
     *
     * @param  \Illuminate\Foundation\Application  $baseApp
     * @return void
     */
    public function __construct(Application $baseApp)
    {
        $this->baseApp = $baseApp;
        
        // We don't want to run the parent constructor as it has side effects
        // like registering the base path, etc. We just want to act as a proxy.
    }

    /**
     * Get the current application instance for the active coroutine.
     *
     * @return \Illuminate\Foundation\Application
     */
    protected function getCurrentApp()
    {
        // If we are in a coroutine, try to get the context-specific app
        if (Coroutine::getCid() > 0) {
            $app = Context::get('octane.app');
            
            if ($app) {
                return $app;
            }
        }

        // Fallback to the base application (e.g. during boot or outside coroutines)
        return $this->baseApp;
    }

    /**
     * Resolve the given type from the container.
     *
     * @param  string  $abstract
     * @param  array  $parameters
     * @return mixed
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function make($abstract, array $parameters = [])
    {
        return $this->getCurrentApp()->make($abstract, $parameters);
    }

    /**
     * Determine if the given abstract type has been bound.
     *
     * @param  string  $abstract
     * @return bool
     */
    public function bound($abstract)
    {
        return $this->getCurrentApp()->bound($abstract);
    }

    /**
     * Determine if the given abstract type has been resolved.
     *
     * @param  string  $abstract
     * @return bool
     */
    public function resolved($abstract)
    {
        return $this->getCurrentApp()->resolved($abstract);
    }

    /**
     * Get the container's bindings.
     *
     * @return array
     */
    public function getBindings()
    {
        return $this->getCurrentApp()->getBindings();
    }

    /**
     * Register a shared binding in the container.
     *
     * @param  string|array  $abstract
     * @param  \Closure|string|null  $concrete
     * @return void
     */
    public function singleton($abstract, $concrete = null)
    {
        $this->getCurrentApp()->singleton($abstract, $concrete);
    }

    /**
     * Register a binding with the container.
     *
     * @param  string|array  $abstract
     * @param  \Closure|string|null  $concrete
     * @param  bool  $shared
     * @return void
     */
    public function bind($abstract, $concrete = null, $shared = false)
    {
        $this->getCurrentApp()->bind($abstract, $concrete, $shared);
    }

    /**
     * Register an existing instance as shared in the container.
     *
     * @param  string  $abstract
     * @param  mixed  $instance
     * @return mixed
     */
    public function instance($abstract, $instance)
    {
        return $this->getCurrentApp()->instance($abstract, $instance);
    }

    /**
     * Call the given Closure / class@method and inject its dependencies.
     *
     * @param  callable|string  $callback
     * @param  array<string, mixed>  $parameters
     * @param  string|null  $defaultMethod
     * @return mixed
     */
    public function call($callback, array $parameters = [], $defaultMethod = null)
    {
        return $this->getCurrentApp()->call($callback, $parameters, $defaultMethod);
    }

    /**
     * Get the value at a given offset.
     *
     * @param  string  $key
     * @return mixed
     */
    public function offsetGet($key): mixed
    {
        return $this->getCurrentApp()->offsetGet($key);
    }

    /**
     * Set the value at a given offset.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    public function offsetSet($key, $value): void
    {
        $this->getCurrentApp()->offsetSet($key, $value);
    }

    /**
     * Determine if a given offset exists.
     *
     * @param  string  $key
     * @return bool
     */
    public function offsetExists($key): bool
    {
        return $this->getCurrentApp()->offsetExists($key);
    }

    /**
     * Unset the value at a given offset.
     *
     * @param  string  $key
     * @return void
     */
    public function offsetUnset($key): void
    {
        $this->getCurrentApp()->offsetUnset($key);
    }

    /**
     * Dynamically access application services.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->getCurrentApp()->$key;
    }

    /**
     * Dynamically set application services.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    public function __set($key, $value)
    {
        $this->getCurrentApp()->$key = $value;
    }

    /**
     * Dynamically handle calls to the application.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->getCurrentApp()->$method(...$parameters);
    }

    /**
     * Get the base path of the Laravel installation.
     *
     * @param  string  $path
     * @return string
     */
    public function basePath($path = '')
    {
        return $this->getCurrentApp()->basePath($path);
    }

    /**
     * Get the path to the public / web directory.
     *
     * @param  string  $path
     * @return string
     */
    public function publicPath($path = '')
    {
        return $this->getCurrentApp()->publicPath($path);
    }

    /**
     * Get the path to the storage directory.
     *
     * @param  string  $path
     * @return string
     */
    public function storagePath($path = '')
    {
        return $this->getCurrentApp()->storagePath($path);
    }

    /**
     * Get the path to the resources directory.
     *
     * @param  string  $path
     * @return string
     */
    public function resourcePath($path = '')
    {
        return $this->getCurrentApp()->resourcePath($path);
    }

    /**
     * Get the path to the database directory.
     *
     * @param  string  $path
     * @return string
     */
    public function databasePath($path = '')
    {
        return $this->getCurrentApp()->databasePath($path);
    }

    /**
     * Get the path to the language files.
     *
     * @param  string  $path
     * @return string
     */
    public function langPath($path = '')
    {
        return $this->getCurrentApp()->langPath($path);
    }

    /**
     * Get the path to the bootstrap directory.
     *
     * @param  string  $path
     * @return string
     */
    public function bootstrapPath($path = '')
    {
        return $this->getCurrentApp()->bootstrapPath($path);
    }

    /**
     * Get the path to the application configuration files.
     *
     * @param  string  $path
     * @return string
     */
    public function configPath($path = '')
    {
        return $this->getCurrentApp()->configPath($path);
    }

    /**
     * Get the path to the application "app" directory.
     *
     * @param  string  $path
     * @return string
     */
    public function path($path = '')
    {
        return $this->getCurrentApp()->path($path);
    }

    /**
     * Get or check the current application environment.
     *
     * @param  string|array  ...$environments
     * @return string|bool
     */
    public function environment(...$environments)
    {
        return $this->getCurrentApp()->environment(...$environments);
    }

    /**
     * Determine if the application is running in the console.
     *
     * @return bool
     */
    public function runningInConsole()
    {
        return $this->getCurrentApp()->runningInConsole();
    }

    /**
     * Determine if the application is running unit tests.
     *
     * @return bool
     */
    public function runningUnitTests()
    {
        return $this->getCurrentApp()->runningUnitTests();
    }

    /**
     * Get the version number of the application.
     *
     * @return string
     */
    public function version()
    {
        return $this->getCurrentApp()->version();
    }
}
