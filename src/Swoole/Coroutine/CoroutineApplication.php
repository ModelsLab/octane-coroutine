<?php

namespace Laravel\Octane\Swoole\Coroutine;

use Illuminate\Foundation\Application;
use Illuminate\Container\Container;
use Swoole\Coroutine;
use Closure;

/**
 * Coroutine-aware Application Proxy
 *
 * This class acts as a proxy to the real Laravel Application instance.
 * It delegates ALL calls to the coroutine-specific sandbox application
 * when running in a coroutine, or falls back to the base application.
 *
 * CRITICAL: This class MUST override ALL public methods from Application
 * because __call() only catches undefined methods. Since we extend Application
 * but don't call parent constructor, any defined method would fail.
 */
class CoroutineApplication extends Application
{
    /**
     * The base application instance (shared across all coroutines).
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

        // We don't call parent constructor - this is intentional.
        // We act purely as a proxy to the current context's application.
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

            if ($app && $app !== $this) {
                return $app;
            }
        }

        // Fallback to the base application (e.g., during boot or outside coroutines)
        return $this->baseApp;
    }

    /**
     * Get the current coroutine request scope if one exists.
     */
    protected function getRequestScope(): ?RequestScope
    {
        $scope = Context::get('octane.request_scope');

        return $scope instanceof RequestScope ? $scope : null;
    }

    /**
     * Normalize an abstract using the base application's alias table.
     *
     * @param  mixed  $abstract
     * @return mixed
     */
    protected function normalizeAbstract($abstract)
    {
        if (! is_string($abstract)) {
            return $abstract;
        }

        return $this->baseApp->getAlias($abstract);
    }

    /**
     * Determine if the given abstract has a coroutine-scoped instance.
     *
     * @param  mixed  $abstract
     */
    protected function hasScopedInstance($abstract): bool
    {
        $scope = $this->getRequestScope();

        return $scope instanceof RequestScope
            && is_string($abstract = $this->normalizeAbstract($abstract))
            && $scope->has($abstract);
    }

    /**
     * Determine if the given abstract should resolve to the proxy itself.
     *
     * @param  mixed  $abstract
     */
    protected function resolvesToProxy($abstract): bool
    {
        return is_string($abstract) && $this->normalizeAbstract($abstract) === 'app';
    }

    /**
     * Get the base application (used for worker-level operations).
     *
     * @return \Illuminate\Foundation\Application
     */
    public function getBaseApplication(): Application
    {
        return $this->baseApp;
    }

    // =========================================================================
    // Container Methods
    // =========================================================================

    /**
     * Resolve the given type from the container.
     *
     * @param  string  $abstract
     * @param  array  $parameters
     * @return mixed
     */
    public function make($abstract, array $parameters = [])
    {
        if ($this->resolvesToProxy($abstract)) {
            return $this;
        }

        $scope = $this->getRequestScope();

        if ($scope instanceof RequestScope && is_string($abstract = $this->normalizeAbstract($abstract))) {
            if ($scope->has($abstract)) {
                return $scope->get($abstract);
            }

            $resolved = $scope->resolve($abstract, $this);

            if ($scope->has($abstract)) {
                return $resolved;
            }
        }

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
        if ($this->resolvesToProxy($abstract) || $this->hasScopedInstance($abstract)) {
            return true;
        }

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
        if ($this->resolvesToProxy($abstract) || $this->hasScopedInstance($abstract)) {
            return true;
        }

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
        if ($this->resolvesToProxy($abstract)) {
            return $this;
        }

        $scope = $this->getRequestScope();

        if ($scope instanceof RequestScope && is_string($abstract = $this->normalizeAbstract($abstract))) {
            $scope->set($abstract, $instance);

            return $instance;
        }

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
     * Alias a type to a different name.
     *
     * @param  string  $abstract
     * @param  string  $alias
     * @return void
     */
    public function alias($abstract, $alias)
    {
        $this->getCurrentApp()->alias($abstract, $alias);
    }

    /**
     * Assign a set of tags to a given binding.
     *
     * @param  array|string  $abstracts
     * @param  array|mixed  ...$tags
     * @return void
     */
    public function tag($abstracts, $tags)
    {
        $this->getCurrentApp()->tag($abstracts, $tags);
    }

    /**
     * Resolve all of the bindings for a given tag.
     *
     * @param  string  $tag
     * @return iterable
     */
    public function tagged($tag)
    {
        return $this->getCurrentApp()->tagged($tag);
    }

    /**
     * Register a binding if it hasn't already been registered.
     *
     * @param  string  $abstract
     * @param  \Closure|string|null  $concrete
     * @param  bool  $shared
     * @return void
     */
    public function bindIf($abstract, $concrete = null, $shared = false)
    {
        $this->getCurrentApp()->bindIf($abstract, $concrete, $shared);
    }

    /**
     * Register a shared binding if it hasn't already been registered.
     *
     * @param  string  $abstract
     * @param  \Closure|string|null  $concrete
     * @return void
     */
    public function singletonIf($abstract, $concrete = null)
    {
        $this->getCurrentApp()->singletonIf($abstract, $concrete);
    }

    /**
     * Register a scoped binding in the container.
     *
     * @param  string  $abstract
     * @param  \Closure|string|null  $concrete
     * @return void
     */
    public function scoped($abstract, $concrete = null)
    {
        $this->getCurrentApp()->scoped($abstract, $concrete);
    }

    /**
     * Register a scoped binding if it hasn't already been registered.
     *
     * @param  string  $abstract
     * @param  \Closure|string|null  $concrete
     * @return void
     */
    public function scopedIf($abstract, $concrete = null)
    {
        $this->getCurrentApp()->scopedIf($abstract, $concrete);
    }

    /**
     * "Extend" an abstract type in the container.
     *
     * @param  string  $abstract
     * @param  \Closure  $closure
     * @return void
     */
    public function extend($abstract, Closure $closure)
    {
        $this->getCurrentApp()->extend($abstract, $closure);
    }

    /**
     * Register a new before resolving callback.
     *
     * @param  \Closure|string  $abstract
     * @param  \Closure|null  $callback
     * @return void
     */
    public function beforeResolving($abstract, Closure $callback = null)
    {
        $this->getCurrentApp()->beforeResolving($abstract, $callback);
    }

    /**
     * Register a new resolving callback.
     *
     * @param  \Closure|string  $abstract
     * @param  \Closure|null  $callback
     * @return void
     */
    public function resolving($abstract, Closure $callback = null)
    {
        $this->getCurrentApp()->resolving($abstract, $callback);
    }

    /**
     * Register a new after resolving callback.
     *
     * @param  \Closure|string  $abstract
     * @param  \Closure|null  $callback
     * @return void
     */
    public function afterResolving($abstract, Closure $callback = null)
    {
        $this->getCurrentApp()->afterResolving($abstract, $callback);
    }

    /**
     * Get the alias for an abstract if available.
     *
     * @param  string  $abstract
     * @return string
     */
    public function getAlias($abstract)
    {
        return $this->getCurrentApp()->getAlias($abstract);
    }

    /**
     * Remove all of the extender callbacks for a given type.
     *
     * @param  string  $abstract
     * @return void
     */
    public function forgetExtenders($abstract)
    {
        $this->getCurrentApp()->forgetExtenders($abstract);
    }

    /**
     * Remove a resolved instance from the instance cache.
     *
     * @param  string  $abstract
     * @return void
     */
    public function forgetInstance($abstract)
    {
        if ($this->resolvesToProxy($abstract)) {
            return;
        }

        $scope = $this->getRequestScope();

        if ($scope instanceof RequestScope && is_string($abstract = $this->normalizeAbstract($abstract))) {
            $scope->forget($abstract);

            return;
        }

        $this->getCurrentApp()->forgetInstance($abstract);
    }

    /**
     * Clear all of the instances from the container.
     *
     * @return void
     */
    public function forgetInstances()
    {
        if (($scope = $this->getRequestScope()) instanceof RequestScope) {
            $scope->clear();

            return;
        }

        $this->getCurrentApp()->forgetInstances();
    }

    /**
     * Clear all of the scoped instances from the container.
     *
     * @return void
     */
    public function forgetScopedInstances()
    {
        if (($scope = $this->getRequestScope()) instanceof RequestScope) {
            $scope->clear();

            return;
        }

        $this->getCurrentApp()->forgetScopedInstances();
    }

    /**
     * Flush the container of all bindings and resolved instances.
     *
     * @return void
     */
    public function flush()
    {
        if (($scope = $this->getRequestScope()) instanceof RequestScope) {
            $scope->clear();

            return;
        }

        $this->getCurrentApp()->flush();
    }

    // =========================================================================
    // ArrayAccess Implementation
    // =========================================================================

    /**
     * Get the value at a given offset.
     *
     * @param  string  $key
     * @return mixed
     */
    public function offsetGet($key): mixed
    {
        return $this->make($key);
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
        if ($this->getRequestScope() instanceof RequestScope && ! $value instanceof Closure) {
            $this->instance($key, $value);

            return;
        }

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
        return $this->bound($key);
    }

    /**
     * Unset the value at a given offset.
     *
     * @param  string  $key
     * @return void
     */
    public function offsetUnset($key): void
    {
        $this->forgetInstance($key);
    }

    // =========================================================================
    // Path Methods
    // =========================================================================

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
     * Get the path to the views directory.
     *
     * @param  string  $path
     * @return string
     */
    public function viewPath($path = '')
    {
        return $this->getCurrentApp()->viewPath($path);
    }

    /**
     * Join the given paths together.
     *
     * @param  string  $basePath
     * @param  string  $path
     * @return string
     */
    public function joinPaths($basePath, $path = '')
    {
        return $this->getCurrentApp()->joinPaths($basePath, $path);
    }

    // =========================================================================
    // Environment Methods
    // =========================================================================

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
     * Determine if the application is in the local environment.
     *
     * @return bool
     */
    public function isLocal()
    {
        return $this->getCurrentApp()->isLocal();
    }

    /**
     * Determine if the application is in the production environment.
     *
     * @return bool
     */
    public function isProduction()
    {
        return $this->getCurrentApp()->isProduction();
    }

    /**
     * Detect the application's current environment.
     *
     * @param  \Closure  $callback
     * @return string
     */
    public function detectEnvironment(Closure $callback)
    {
        return $this->getCurrentApp()->detectEnvironment($callback);
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
     * Determine if the application has been bootstrapped before.
     *
     * @return bool
     */
    public function hasBeenBootstrapped()
    {
        return $this->getCurrentApp()->hasBeenBootstrapped();
    }

    // =========================================================================
    // Service Provider Methods
    // =========================================================================

    /**
     * Register a service provider with the application.
     *
     * @param  \Illuminate\Support\ServiceProvider|string  $provider
     * @param  bool  $force
     * @return \Illuminate\Support\ServiceProvider
     */
    public function register($provider, $force = false)
    {
        return $this->getCurrentApp()->register($provider, $force);
    }

    /**
     * Get the registered service provider instance if it exists.
     *
     * @param  \Illuminate\Support\ServiceProvider|string  $provider
     * @return \Illuminate\Support\ServiceProvider|null
     */
    public function getProvider($provider)
    {
        return $this->getCurrentApp()->getProvider($provider);
    }

    /**
     * Get the registered service provider instances if any exist.
     *
     * @param  \Illuminate\Support\ServiceProvider|string  $provider
     * @return array
     */
    public function getProviders($provider)
    {
        return $this->getCurrentApp()->getProviders($provider);
    }

    /**
     * Resolve a service provider instance from the class name.
     *
     * @param  string  $provider
     * @return \Illuminate\Support\ServiceProvider
     */
    public function resolveProvider($provider)
    {
        return $this->getCurrentApp()->resolveProvider($provider);
    }

    /**
     * Load and boot all of the remaining deferred providers.
     *
     * @return void
     */
    public function loadDeferredProviders()
    {
        $this->getCurrentApp()->loadDeferredProviders();
    }

    /**
     * Load the provider for a deferred service.
     *
     * @param  string  $service
     * @return void
     */
    public function loadDeferredProvider($service)
    {
        $this->getCurrentApp()->loadDeferredProvider($service);
    }

    /**
     * Register a deferred provider and service.
     *
     * @param  string  $provider
     * @param  string|null  $service
     * @return void
     */
    public function registerDeferredProvider($provider, $service = null)
    {
        $this->getCurrentApp()->registerDeferredProvider($provider, $service);
    }

    /**
     * Boot the application's service providers.
     *
     * @return void
     */
    public function boot()
    {
        $this->getCurrentApp()->boot();
    }

    /**
     * Register a new boot listener.
     *
     * @param  callable  $callback
     * @return void
     */
    public function booting($callback)
    {
        $this->getCurrentApp()->booting($callback);
    }

    /**
     * Register a new "booted" listener.
     *
     * @param  callable  $callback
     * @return void
     */
    public function booted($callback)
    {
        $this->getCurrentApp()->booted($callback);
    }

    /**
     * Determine if the application has booted.
     *
     * @return bool
     */
    public function isBooted()
    {
        return $this->getCurrentApp()->isBooted();
    }

    /**
     * Get the service providers that have been loaded.
     *
     * @return array
     */
    public function getLoadedProviders()
    {
        return $this->getCurrentApp()->getLoadedProviders();
    }

    /**
     * Determine if the given service provider is loaded.
     *
     * @param  string  $provider
     * @return bool
     */
    public function providerIsLoaded(string $provider)
    {
        return $this->getCurrentApp()->providerIsLoaded($provider);
    }

    /**
     * Get the application's deferred services.
     *
     * @return array
     */
    public function getDeferredServices()
    {
        return $this->getCurrentApp()->getDeferredServices();
    }

    /**
     * Set the application's deferred services.
     *
     * @param  array  $services
     * @return void
     */
    public function setDeferredServices(array $services)
    {
        $this->getCurrentApp()->setDeferredServices($services);
    }

    /**
     * Add an array of services to the application's deferred services.
     *
     * @param  array  $services
     * @return void
     */
    public function addDeferredServices(array $services)
    {
        $this->getCurrentApp()->addDeferredServices($services);
    }

    /**
     * Determine if the given service is a deferred service.
     *
     * @param  string  $service
     * @return bool
     */
    public function isDeferredService($service)
    {
        return $this->getCurrentApp()->isDeferredService($service);
    }

    // =========================================================================
    // Bootstrapping Methods
    // =========================================================================

    /**
     * Run the given array of bootstrap classes.
     *
     * @param  string[]  $bootstrappers
     * @return void
     */
    public function bootstrapWith(array $bootstrappers)
    {
        $this->getCurrentApp()->bootstrapWith($bootstrappers);
    }

    // =========================================================================
    // Locale Methods
    // =========================================================================

    /**
     * Get the current application locale.
     *
     * @return string
     */
    public function getLocale()
    {
        return $this->getCurrentApp()->getLocale();
    }

    /**
     * Get the current application locale.
     *
     * @return string
     */
    public function currentLocale()
    {
        return $this->getCurrentApp()->currentLocale();
    }

    /**
     * Get the current application fallback locale.
     *
     * @return string
     */
    public function getFallbackLocale()
    {
        return $this->getCurrentApp()->getFallbackLocale();
    }

    /**
     * Set the current application locale.
     *
     * @param  string  $locale
     * @return void
     */
    public function setLocale($locale)
    {
        $this->getCurrentApp()->setLocale($locale);
    }

    /**
     * Set the current application fallback locale.
     *
     * @param  string  $fallbackLocale
     * @return void
     */
    public function setFallbackLocale($fallbackLocale)
    {
        $this->getCurrentApp()->setFallbackLocale($fallbackLocale);
    }

    /**
     * Determine if the application locale is the given locale.
     *
     * @param  string  $locale
     * @return bool
     */
    public function isLocale($locale)
    {
        return $this->getCurrentApp()->isLocale($locale);
    }

    // =========================================================================
    // Misc Application Methods
    // =========================================================================

    /**
     * Get the version number of the application.
     *
     * @return string
     */
    public function version()
    {
        return $this->getCurrentApp()->version();
    }

    /**
     * Register a terminating callback with the application.
     *
     * @param  callable|string  $callback
     * @return $this
     */
    public function terminating($callback)
    {
        $this->getCurrentApp()->terminating($callback);
        return $this;
    }

    /**
     * Terminate the application.
     *
     * @return void
     */
    public function terminate()
    {
        $this->getCurrentApp()->terminate();
    }

    /**
     * Register the core class aliases in the container.
     *
     * @return void
     */
    public function registerCoreContainerAliases()
    {
        $this->getCurrentApp()->registerCoreContainerAliases();
    }

    /**
     * Get the application namespace.
     *
     * @return string
     */
    public function getNamespace()
    {
        return $this->getCurrentApp()->getNamespace();
    }

    /**
     * Throw an HttpException with the given data.
     *
     * @param  int  $code
     * @param  string  $message
     * @param  array  $headers
     * @return never
     */
    public function abort($code, $message = '', array $headers = [])
    {
        $this->getCurrentApp()->abort($code, $message, $headers);
    }

    /**
     * Determine if middleware has been disabled for the application.
     *
     * @return bool
     */
    public function shouldSkipMiddleware()
    {
        return $this->getCurrentApp()->shouldSkipMiddleware();
    }

    /**
     * Get the path to the cached services.php file.
     *
     * @return string
     */
    public function getCachedServicesPath()
    {
        return $this->getCurrentApp()->getCachedServicesPath();
    }

    /**
     * Get the path to the cached packages.php file.
     *
     * @return string
     */
    public function getCachedPackagesPath()
    {
        return $this->getCurrentApp()->getCachedPackagesPath();
    }

    /**
     * Determine if the application configuration is cached.
     *
     * @return bool
     */
    public function configurationIsCached()
    {
        return $this->getCurrentApp()->configurationIsCached();
    }

    /**
     * Get the path to the configuration cache file.
     *
     * @return string
     */
    public function getCachedConfigPath()
    {
        return $this->getCurrentApp()->getCachedConfigPath();
    }

    /**
     * Determine if the application routes are cached.
     *
     * @return bool
     */
    public function routesAreCached()
    {
        return $this->getCurrentApp()->routesAreCached();
    }

    /**
     * Get the path to the routes cache file.
     *
     * @return string
     */
    public function getCachedRoutesPath()
    {
        return $this->getCurrentApp()->getCachedRoutesPath();
    }

    /**
     * Determine if the application events are cached.
     *
     * @return bool
     */
    public function eventsAreCached()
    {
        return $this->getCurrentApp()->eventsAreCached();
    }

    /**
     * Get the path to the events cache file.
     *
     * @return string
     */
    public function getCachedEventsPath()
    {
        return $this->getCurrentApp()->getCachedEventsPath();
    }

    /**
     * Determine if the framework's base configuration should be merged.
     *
     * @return bool
     */
    public function shouldMergeFrameworkConfiguration()
    {
        return $this->getCurrentApp()->shouldMergeFrameworkConfiguration();
    }

    /**
     * Indicate that the framework's base configuration should not be merged.
     *
     * @return $this
     */
    public function dontMergeFrameworkConfiguration()
    {
        $this->getCurrentApp()->dontMergeFrameworkConfiguration();
        return $this;
    }

    // =========================================================================
    // Maintenance Mode Methods
    // =========================================================================

    /**
     * Determine if the application is currently down for maintenance.
     *
     * @return bool
     */
    public function isDownForMaintenance()
    {
        return $this->getCurrentApp()->isDownForMaintenance();
    }

    /**
     * Get the path to the maintenance file.
     *
     * @return string
     */
    public function maintenanceMode()
    {
        return $this->getCurrentApp()->maintenanceMode();
    }

    // =========================================================================
    // Additional Application / Container Methods
    // =========================================================================

    public function addAbsoluteCachePathPrefix($prefix)
    {
        return $this->getCurrentApp()->addAbsoluteCachePathPrefix($prefix);
    }

    public function addContextualBinding($concrete, $abstract, $implementation)
    {
        return $this->getCurrentApp()->addContextualBinding($concrete, $abstract, $implementation);
    }

    public function afterBootstrapping($bootstrapper, \Closure $callback)
    {
        return $this->getCurrentApp()->afterBootstrapping($bootstrapper, $callback);
    }

    public function afterLoadingEnvironment(\Closure $callback)
    {
        return $this->getCurrentApp()->afterLoadingEnvironment($callback);
    }

    public function afterResolvingAttribute(string $attribute, \Closure $callback)
    {
        return $this->getCurrentApp()->afterResolvingAttribute($attribute, $callback);
    }

    public function beforeBootstrapping($bootstrapper, \Closure $callback)
    {
        return $this->getCurrentApp()->beforeBootstrapping($bootstrapper, $callback);
    }

    public function bindMethod($method, $callback)
    {
        return $this->getCurrentApp()->bindMethod($method, $callback);
    }

    public function build($concrete)
    {
        return $this->getCurrentApp()->build($concrete);
    }

    public function callMethodBinding($method, $instance)
    {
        return $this->getCurrentApp()->callMethodBinding($method, $instance);
    }

    public static function configure(?string $basePath = null)
    {
        return parent::configure($basePath);
    }

    public function currentEnvironmentIs($environments)
    {
        return $this->getCurrentApp()->currentEnvironmentIs($environments);
    }

    public function currentlyResolving()
    {
        return $this->getCurrentApp()->currentlyResolving();
    }

    public function environmentFile()
    {
        return $this->getCurrentApp()->environmentFile();
    }

    public function environmentFilePath()
    {
        return $this->getCurrentApp()->environmentFilePath();
    }

    public function environmentPath()
    {
        return $this->getCurrentApp()->environmentPath();
    }

    public function factory($abstract)
    {
        return $this->getCurrentApp()->factory($abstract);
    }

    public function fireAfterResolvingAttributeCallbacks(array $attributes, $object)
    {
        return $this->getCurrentApp()->fireAfterResolvingAttributeCallbacks($attributes, $object);
    }

    public static function flushMacros()
    {
        return parent::flushMacros();
    }

    public function get(string $id)
    {
        return $this->make($id);
    }

    public function getBootstrapProvidersPath()
    {
        return $this->getCurrentApp()->getBootstrapProvidersPath();
    }

    public static function getInstance()
    {
        return parent::getInstance();
    }

    public function handle(\Symfony\Component\HttpFoundation\Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): \Symfony\Component\HttpFoundation\Response
    {
        return $this->getCurrentApp()->handle($request, $type, $catch);
    }

    public function handleCommand(\Symfony\Component\Console\Input\InputInterface $input)
    {
        return $this->getCurrentApp()->handleCommand($input);
    }

    public function handleRequest(\Illuminate\Http\Request $request)
    {
        return $this->getCurrentApp()->handleRequest($request);
    }

    public function has(string $id): bool
    {
        return $this->bound($id);
    }

    public function hasDebugModeEnabled()
    {
        return $this->getCurrentApp()->hasDebugModeEnabled();
    }

    public static function hasMacro($name)
    {
        return parent::hasMacro($name);
    }

    public function hasMethodBinding($method)
    {
        return $this->getCurrentApp()->hasMethodBinding($method);
    }

    public static function inferBasePath()
    {
        return parent::inferBasePath();
    }

    public function isAlias($name)
    {
        return $this->getCurrentApp()->isAlias($name);
    }

    public function isShared($abstract)
    {
        if ($this->resolvesToProxy($abstract) || $this->hasScopedInstance($abstract)) {
            return true;
        }

        return $this->getCurrentApp()->isShared($abstract);
    }

    public function loadEnvironmentFrom($file)
    {
        return $this->getCurrentApp()->loadEnvironmentFrom($file);
    }

    public static function macro($name, $macro)
    {
        return parent::macro($name, $macro);
    }

    public function makeWith($abstract, array $parameters = [])
    {
        return $this->make($abstract, $parameters);
    }

    public static function mixin($mixin, $replace = true)
    {
        return parent::mixin($mixin, $replace);
    }

    public function provideFacades($namespace)
    {
        return $this->getCurrentApp()->provideFacades($namespace);
    }

    public function rebinding($abstract, \Closure $callback)
    {
        return $this->getCurrentApp()->rebinding($abstract, $callback);
    }

    public function refresh($abstract, $target, $method)
    {
        return $this->getCurrentApp()->refresh($abstract, $target, $method);
    }

    public function registerConfiguredProviders()
    {
        return $this->getCurrentApp()->registerConfiguredProviders();
    }

    public function registered($callback)
    {
        return $this->getCurrentApp()->registered($callback);
    }

    public function removeDeferredServices(array $services)
    {
        return $this->getCurrentApp()->removeDeferredServices($services);
    }

    public function resolveEnvironmentUsing(?callable $callback)
    {
        return $this->getCurrentApp()->resolveEnvironmentUsing($callback);
    }

    public function resolveFromAttribute(\ReflectionAttribute $attribute)
    {
        return $this->getCurrentApp()->resolveFromAttribute($attribute);
    }

    public function runningConsoleCommand(...$commands)
    {
        return $this->getCurrentApp()->runningConsoleCommand(...$commands);
    }

    public function setBasePath($basePath)
    {
        return $this->getCurrentApp()->setBasePath($basePath);
    }

    public static function setInstance(?\Illuminate\Contracts\Container\Container $container = null)
    {
        return parent::setInstance($container);
    }

    public function useAppPath($path)
    {
        return $this->getCurrentApp()->useAppPath($path);
    }

    public function useBootstrapPath($path)
    {
        return $this->getCurrentApp()->useBootstrapPath($path);
    }

    public function useConfigPath($path)
    {
        return $this->getCurrentApp()->useConfigPath($path);
    }

    public function useDatabasePath($path)
    {
        return $this->getCurrentApp()->useDatabasePath($path);
    }

    public function useEnvironmentPath($path)
    {
        return $this->getCurrentApp()->useEnvironmentPath($path);
    }

    public function useLangPath($path)
    {
        return $this->getCurrentApp()->useLangPath($path);
    }

    public function usePublicPath($path)
    {
        return $this->getCurrentApp()->usePublicPath($path);
    }

    public function useStoragePath($path)
    {
        return $this->getCurrentApp()->useStoragePath($path);
    }

    public function when($concrete)
    {
        return $this->getCurrentApp()->when($concrete);
    }

    public function whenHasAttribute(string $attribute, \Closure $handler)
    {
        return $this->getCurrentApp()->whenHasAttribute($attribute, $handler);
    }

    public function wrap(\Closure $callback, array $parameters = [])
    {
        return $this->getCurrentApp()->wrap($callback, $parameters);
    }

    // =========================================================================
    // Magic Methods
    // =========================================================================

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
     * This catches any method not explicitly overridden above.
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
     * Handle static method calls.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public static function __callStatic($method, $parameters)
    {
        return Container::getInstance()->$method(...$parameters);
    }
}
