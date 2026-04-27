<?php

namespace Laravel\Octane\Swoole\Coroutine;

use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Redis\RedisManager;
use Illuminate\Session\SessionManager;
use ReflectionClass;
use ReflectionException;

/**
 * Lightweight per-request state storage for coroutine isolation.
 *
 * Instead of cloning the entire Application (~3-5MB) per request,
 * this class holds only the request-scoped bindings that need
 * per-coroutine isolation (request, session, auth, config, url, cookie).
 *
 * Most process-scoped bindings remain on the shared base application.
 * Redis-backed managers are scoped because shared phpredis sockets are
 * not safe to reuse across concurrent coroutines in the same worker.
 */
class RequestScope
{
    /**
     * The request-scoped bindings for this coroutine.
     *
     * @var array<string, mixed>
     */
    private array $bindings = [];

    /**
     * Request-local binding definitions registered during the current request.
     *
     * @var array<string, array{concrete: mixed, shared: bool}>
     */
    private array $bindingDefinitions = [];

    /**
     * Resolved shared instances for request-local binding definitions.
     *
     * @var array<string, mixed>
     */
    private array $resolvedBindings = [];

    /**
     * The base application instance (shared, read-only reference).
     *
     * @var \Illuminate\Foundation\Application
     */
    private Application $app;

    /**
     * Whether the config has been cloned for copy-on-write.
     *
     * @var bool
     */
    private bool $configCloned = false;

    /**
     * Create a new RequestScope instance.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @param  \Illuminate\Http\Request|null  $request
     * @return void
     */
    public function __construct(Application $app, ?Request $request = null)
    {
        $this->app = $app;

        if ($request !== null) {
            $this->bindings['request'] = $request;
        }
    }

    /**
     * Check if a binding exists in this request scope.
     *
     * @param  string  $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->bindings);
    }

    /**
     * Get a binding from this request scope.
     *
     * @param  string  $key
     * @return mixed
     */
    public function get(string $key)
    {
        return $this->bindings[$key] ?? null;
    }

    /**
     * Set a binding in this request scope.
     *
     * For 'config', this triggers a copy-on-write clone on first mutation
     * so that config changes in one coroutine don't leak to others.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    public function set(string $key, $value): void
    {
        $this->bindings[$key] = $value;

        if ($key === 'config') {
            $this->configCloned = true;
        }
    }

    /**
     * Remove a binding from this request scope.
     *
     * @param  string  $key
     * @return void
     */
    public function forget(string $key): void
    {
        unset($this->bindings[$key], $this->bindingDefinitions[$key], $this->resolvedBindings[$key]);
    }

    /**
     * Register a request-local binding.
     *
     * @param  string  $key
     * @param  mixed  $concrete
     * @param  bool  $shared
     * @return void
     */
    public function bind(string $key, $concrete = null, bool $shared = false): void
    {
        $this->bindingDefinitions[$key] = [
            'concrete' => $concrete ?? $key,
            'shared' => $shared,
        ];

        unset($this->resolvedBindings[$key], $this->bindings[$key]);
    }

    /**
     * Determine if a request-local binding exists.
     *
     * @param  string  $key
     * @return bool
     */
    public function hasBinding(string $key): bool
    {
        return array_key_exists($key, $this->bindingDefinitions);
    }

    /**
     * Determine if a request-local binding has already been resolved.
     *
     * @param  string  $key
     * @return bool
     */
    public function resolvedBinding(string $key): bool
    {
        return array_key_exists($key, $this->resolvedBindings);
    }

    /**
     * Resolve a request-local binding definition.
     *
     * @param  string  $key
     * @param  \Illuminate\Foundation\Application  $sandbox
     * @param  array<int|string, mixed>  $parameters
     * @return mixed
     */
    public function resolveBinding(string $key, Application $sandbox, array $parameters = [])
    {
        $definition = $this->bindingDefinitions[$key] ?? null;

        if ($definition === null) {
            return null;
        }

        if ($definition['shared'] && array_key_exists($key, $this->resolvedBindings)) {
            return $this->resolvedBindings[$key];
        }

        $concrete = $definition['concrete'];

        $resolved = match (true) {
            $concrete instanceof Closure => $concrete($sandbox, $parameters),
            is_string($concrete) && method_exists($sandbox, 'buildScopedConcrete') => $sandbox->buildScopedConcrete($concrete, $parameters),
            is_string($concrete) => $sandbox->make($concrete, $parameters),
            default => $concrete,
        };

        if ($definition['shared']) {
            $this->resolvedBindings[$key] = $resolved;
        }

        return $resolved;
    }

    /**
     * Ensure config is cloned for copy-on-write isolation.
     *
     * Call this before any config()->set() operation to ensure
     * the mutation only affects the current coroutine's config.
     *
     * @return void
     */
    public function ensureConfigCloned(): void
    {
        if ($this->configCloned) {
            return;
        }

        $this->bindings['config'] = $this->cloneConfig();
        $this->configCloned = true;
    }

    /**
     * Lazily resolve a request-scoped binding for the current coroutine.
     *
     * @param  string  $key
     * @param  \Illuminate\Foundation\Application  $sandbox
     * @return mixed
     */
    public function resolve(string $key, Application $sandbox)
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $resolved = $this->resolveSpecializedBinding($key, $sandbox);

        if ($resolved !== null || $key === 'request') {
            $this->bindings[$key] = $resolved;
            $this->rememberAliasBindings($key, $resolved);
        }

        return $resolved;
    }

    /**
     * Resolve bindings that need specialized coroutine-aware instances.
     *
     * @param  string  $key
     * @param  \Illuminate\Foundation\Application  $sandbox
     * @return mixed
     */
    protected function resolveSpecializedBinding(string $key, Application $sandbox)
    {
        if ($this->isHttpKernelBinding($key)) {
            return $this->createHttpKernel($sandbox);
        }

        return match ($key) {
            'auth' => $this->createAuthManager($sandbox),
            'auth.driver' => $this->createAuthDriver($sandbox),
            'cache', \Illuminate\Contracts\Cache\Factory::class => $this->createCacheManager($sandbox),
            'cache.store', \Illuminate\Contracts\Cache\Repository::class => $this->createCacheStore($sandbox),
            'config' => $this->cloneConfig(),
            'cookie' => $this->createCookieJar(),
            \Inertia\ResponseFactory::class => $this->createInertiaResponseFactory(),
            'log', \Psr\Log\LoggerInterface::class => $this->createLogManager($sandbox),
            'redirect' => $this->createRedirector($sandbox),
            'redis', \Illuminate\Contracts\Redis\Factory::class => $this->createRedisManager($sandbox),
            'request', \Illuminate\Http\Request::class, \Symfony\Component\HttpFoundation\Request::class => $this->get('request'),
            'router', \Illuminate\Routing\Router::class, \Illuminate\Contracts\Routing\BindingRegistrar::class, \Illuminate\Contracts\Routing\Registrar::class => $this->createRouter($sandbox),
            'session' => $this->createSessionManager($sandbox),
            'session.store' => $this->createSessionStore($sandbox),
            'translator', \Illuminate\Contracts\Translation\Translator::class, \Illuminate\Translation\Translator::class => $this->createTranslator($sandbox),
            \Illuminate\Session\Middleware\StartSession::class => $this->createStartSessionMiddleware($sandbox),
            'url' => $this->createUrlGenerator($sandbox),
            'view', \Illuminate\Contracts\View\Factory::class => $this->createViewFactory($sandbox),
            \Illuminate\Routing\Contracts\CallableDispatcher::class => new \Illuminate\Routing\CallableDispatcher($sandbox),
            \Illuminate\Routing\Contracts\ControllerDispatcher::class => new \Illuminate\Routing\ControllerDispatcher($sandbox),
            \Illuminate\Contracts\Routing\ResponseFactory::class => $this->createResponseFactory($sandbox),
            default => null,
        };
    }

    /**
     * Determine if the binding is an HTTP kernel implementation.
     */
    protected function isHttpKernelBinding(string $key): bool
    {
        return $key === \Illuminate\Contracts\Http\Kernel::class
            || $key === \Illuminate\Foundation\Http\Kernel::class
            || (class_exists($key) && is_subclass_of($key, \Illuminate\Foundation\Http\Kernel::class));
    }

    /**
     * Store equivalent aliases for scoped objects so repeated resolution
     * within one request returns the same request-local instance.
     *
     * @param  string  $key
     * @param  mixed  $resolved
     * @return void
     */
    protected function rememberAliasBindings(string $key, $resolved): void
    {
        if ($this->isHttpKernelBinding($key) && is_object($resolved)) {
            $this->bindings[\Illuminate\Contracts\Http\Kernel::class] = $resolved;
            $this->bindings[\Illuminate\Foundation\Http\Kernel::class] = $resolved;
            $this->bindings[$resolved::class] = $resolved;

            return;
        }

        match ($key) {
            'request',
            \Illuminate\Http\Request::class,
            \Symfony\Component\HttpFoundation\Request::class => $this->storeScopedAliases([
                'request',
                \Illuminate\Http\Request::class,
                \Symfony\Component\HttpFoundation\Request::class,
            ], $resolved),
            'router',
            \Illuminate\Routing\Router::class,
            \Illuminate\Contracts\Routing\BindingRegistrar::class,
            \Illuminate\Contracts\Routing\Registrar::class => $this->storeScopedAliases([
                'router',
                \Illuminate\Routing\Router::class,
                \Illuminate\Contracts\Routing\BindingRegistrar::class,
                \Illuminate\Contracts\Routing\Registrar::class,
            ], $resolved),
            'view',
            \Illuminate\Contracts\View\Factory::class => $this->storeScopedAliases([
                'view',
                \Illuminate\Contracts\View\Factory::class,
            ], $resolved),
            'translator',
            \Illuminate\Contracts\Translation\Translator::class,
            \Illuminate\Translation\Translator::class => $this->storeScopedAliases([
                'translator',
                \Illuminate\Contracts\Translation\Translator::class,
                \Illuminate\Translation\Translator::class,
            ], $resolved),
            default => null,
        };
    }

    /**
     * Store a resolved instance under multiple equivalent keys.
     *
     * @param  array<int, string>  $aliases
     * @param  mixed  $resolved
     * @return void
     */
    protected function storeScopedAliases(array $aliases, $resolved): void
    {
        foreach ($aliases as $alias) {
            $this->bindings[$alias] = $resolved;
        }
    }

    /**
     * Check if config has been cloned in this scope.
     *
     * @return bool
     */
    public function isConfigCloned(): bool
    {
        return $this->configCloned;
    }

    /**
     * Get all bindings in this request scope.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->bindings;
    }

    /**
     * Clear all bindings and release references.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->releaseScopedResources();

        $this->bindings = [];
        $this->bindingDefinitions = [];
        $this->resolvedBindings = [];
        $this->configCloned = false;
    }

    /**
     * Close request-local managers that can otherwise keep sockets alive after
     * the coroutine finishes.
     *
     * @return void
     */
    private function releaseScopedResources(): void
    {
        $seen = [];

        foreach (array_merge($this->bindings, $this->resolvedBindings) as $resource) {
            if (! is_object($resource)) {
                continue;
            }

            $objectId = spl_object_id($resource);
            if (isset($seen[$objectId])) {
                continue;
            }

            $seen[$objectId] = true;
            $this->releaseScopedResource($resource);
        }
    }

    /**
     * Release a single request-scoped manager.
     *
     * @param  object  $resource
     * @return void
     */
    private function releaseScopedResource(object $resource): void
    {
        try {
            if ($resource instanceof RedisManager) {
                foreach ($this->redisConnectionNames() as $name) {
                    $resource->purge($name);
                }

                return;
            }

            if ($resource instanceof CacheManager) {
                foreach ($this->cacheStoreNames() as $store) {
                    $resource->forgetDriver($store);
                }

                return;
            }

            if ($resource instanceof SessionManager) {
                $resource->forgetDrivers();
            }
        } catch (\Throwable $e) {
            error_log('⚠️ Failed to release request-scoped resource: '.$e->getMessage());
        }
    }

    /**
     * @return array<int, string>
     */
    private function redisConnectionNames(): array
    {
        $redisConfig = $this->app->make('config')->get('database.redis', []);

        if (! is_array($redisConfig)) {
            return ['default'];
        }

        $names = array_filter(array_keys($redisConfig), static function ($name) use ($redisConfig) {
            return is_array($redisConfig[$name] ?? null)
                && ! in_array($name, ['client', 'options', 'clusters'], true);
        });

        return array_values($names ?: ['default']);
    }

    /**
     * @return array<int, string>
     */
    private function cacheStoreNames(): array
    {
        $config = $this->app->make('config');
        $stores = $config->get('cache.stores', []);
        $default = $config->get('cache.default');
        $names = is_array($stores) ? array_keys($stores) : [];

        if (is_string($default) && $default !== '') {
            $names[] = $default;
        }

        return array_values(array_unique(array_filter($names, 'is_string')));
    }

    /**
     * Get the base application reference.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function getApp(): Application
    {
        return $this->app;
    }

    /**
     * Clone the current configuration repository for copy-on-write semantics.
     *
     * @return mixed
     */
    protected function cloneConfig()
    {
        return clone $this->app->make('config');
    }

    /**
     * Create an isolated auth manager bound to the coroutine sandbox.
     *
     * @param  \Illuminate\Foundation\Application  $sandbox
     * @return mixed
     */
    protected function createAuthManager(Application $sandbox)
    {
        $auth = clone $this->app->make('auth');

        if (method_exists($auth, 'setApplication')) {
            $auth->setApplication($sandbox);
        }

        $this->rebindAuthCustomCreators($auth);

        if (method_exists($auth, 'resolveUsersUsing')) {
            $auth->resolveUsersUsing(fn ($guard = null) => $auth->guard($guard)->user());
        }

        if (method_exists($auth, 'forgetGuards')) {
            $auth->forgetGuards();
        }

        return $auth;
    }

    /**
     * Rebind custom guard creators cloned from the base AuthManager.
     *
     * Auth::viaRequest() registers a closure that reads $this->app['request'].
     * Cloning the AuthManager does not rebind that closure's $this, so custom
     * request guards would keep using the worker's base request unless we bind
     * those creators to the coroutine-local clone.
     *
     * @param  object  $auth
     * @return void
     */
    protected function rebindAuthCustomCreators(object $auth): void
    {
        $customCreators = $this->getObjectProperty($auth, 'customCreators');

        if (! is_array($customCreators)) {
            return;
        }

        foreach ($customCreators as $driver => $creator) {
            if (! $creator instanceof Closure) {
                continue;
            }

            $reflection = new \ReflectionFunction($creator);
            if ($reflection->isStatic()) {
                continue;
            }

            $bound = $creator->bindTo($auth, $auth::class);
            if ($bound instanceof Closure) {
                $customCreators[$driver] = $bound;
            }
        }

        $this->setObjectProperty($auth, 'customCreators', $customCreators);
    }

    /**
     * Create the coroutine-local default auth guard.
     *
     * @param  \Illuminate\Foundation\Application  $sandbox
     * @return mixed
     */
    protected function createAuthDriver(Application $sandbox)
    {
        $auth = $this->resolve('auth', $sandbox);

        return $auth?->guard();
    }

    /**
     * Create an isolated cookie jar for the current coroutine.
     *
     * @return mixed
     */
    protected function createCookieJar()
    {
        $cookie = clone $this->app->make('cookie');

        if (method_exists($cookie, 'flushQueuedCookies')) {
            $cookie->flushQueuedCookies();
        }

        return $cookie;
    }

    /**
     * Create an isolated session manager bound to the coroutine sandbox.
     *
     * @param  \Illuminate\Foundation\Application  $sandbox
     * @return mixed
     */
    protected function createSessionManager(Application $sandbox)
    {
        $session = clone $this->app->make('session');

        if (method_exists($session, 'setContainer')) {
            $session->setContainer($sandbox);
        }

        if (method_exists($session, 'forgetDrivers')) {
            $session->forgetDrivers();
        }

        $this->setObjectProperty($session, 'config', $sandbox->make('config'));

        return $session;
    }

    /**
     * Create the coroutine-local session store via the isolated session manager.
     *
     * @param  \Illuminate\Foundation\Application  $sandbox
     * @return mixed
     */
    protected function createSessionStore(Application $sandbox)
    {
        $session = $this->resolve('session', $sandbox);

        return $session?->driver();
    }

    /**
     * Create an isolated translator for the current coroutine.
     *
     * @param  \Illuminate\Foundation\Application  $sandbox
     * @return mixed
     */
    protected function createTranslator(Application $sandbox)
    {
        $translator = clone $this->app->make('translator');
        $config = $sandbox->make('config');

        if (method_exists($translator, 'setLocale')) {
            $translator->setLocale($config->get('app.locale'));
        }

        if (method_exists($translator, 'setFallback')) {
            $translator->setFallback($config->get('app.fallback_locale'));
        }

        return $translator;
    }

    /**
     * Create an isolated StartSession middleware bound to the coroutine sandbox.
     *
     * StartSession is registered as a singleton in Laravel, so resolving it from the
     * shared base container would pin a worker-level SessionManager into the web stack.
     *
     * @param  \Illuminate\Foundation\Application  $sandbox
     * @return \Illuminate\Session\Middleware\StartSession
     */
    protected function createStartSessionMiddleware(Application $sandbox): \Illuminate\Session\Middleware\StartSession
    {
        return new \Illuminate\Session\Middleware\StartSession(
            $this->resolve('session', $sandbox),
            static fn () => $sandbox->make(\Illuminate\Contracts\Cache\Factory::class),
        );
    }

    /**
     * Create an isolated HTTP kernel bound to the coroutine sandbox and router.
     *
     * The framework kernel is registered as a singleton and captures the shared
     * router in its constructor. In coroutine mode we need a per-request clone
     * so kernel request timestamps and router state are not shared.
     *
     * @param  \Illuminate\Foundation\Application  $sandbox
     * @return mixed
     */
    protected function createHttpKernel(Application $sandbox)
    {
        $kernel = clone $this->app->make(\Illuminate\Contracts\Http\Kernel::class);

        if (method_exists($kernel, 'setApplication')) {
            $kernel->setApplication($sandbox);
        } else {
            $this->setObjectProperty($kernel, 'app', $sandbox);
        }

        $this->setObjectProperty($kernel, 'router', $sandbox->make('router'));
        $this->setObjectProperty($kernel, 'requestStartedAt', null);
        $this->invokeObjectMethod($kernel, 'syncMiddlewareToRouter');

        return $kernel;
    }

    /**
     * Create an isolated router bound to the coroutine sandbox.
     *
     * The shared router stores the current request and current route on mutable
     * instance properties, so concurrent requests must not reuse the same object.
     *
     * @param  \Illuminate\Foundation\Application  $sandbox
     * @return \Illuminate\Routing\Router
     */
    protected function createRouter(Application $sandbox): \Illuminate\Routing\Router
    {
        return new ScopedRouter($this->app->make('router'), $sandbox);
    }

    /**
     * Create an isolated Redis manager for the current coroutine.
     *
     * phpredis persistent sockets are process-shared by persistent_id.
     * In coroutine mode that means concurrent requests in one worker can
     * contend on the same socket. The coroutine-local manager disables
     * persistence so each request gets its own short-lived connection.
     *
     * @param  \Illuminate\Foundation\Application  $sandbox
     * @return mixed
     */
    protected function createRedisManager(Application $sandbox)
    {
        $redis = clone $this->app->make('redis');

        $this->setObjectProperty($redis, 'app', $sandbox);
        $this->setObjectProperty($redis, 'connections', []);
        $this->setObjectProperty($redis, 'config', $this->createCoroutineRedisConfig($sandbox));

        return $redis;
    }

    /**
     * Create an isolated cache manager for the current coroutine.
     *
     * @param  \Illuminate\Foundation\Application  $sandbox
     * @return mixed
     */
    protected function createCacheManager(Application $sandbox)
    {
        $cache = clone $this->app->make('cache');

        $this->setObjectProperty($cache, 'app', $sandbox);
        $this->setObjectProperty($cache, 'stores', []);

        return $cache;
    }

    /**
     * Create the coroutine-local default cache repository.
     *
     * @param  \Illuminate\Foundation\Application  $sandbox
     * @return mixed
     */
    protected function createCacheStore(Application $sandbox)
    {
        $cache = $this->resolve('cache', $sandbox);

        return $cache?->store();
    }

    /**
     * Create an isolated Inertia response factory for the current coroutine.
     *
     * @return \Inertia\ResponseFactory
     */
    protected function createInertiaResponseFactory(): \Inertia\ResponseFactory
    {
        return new \Inertia\ResponseFactory;
    }

    /**
     * Create an isolated view factory for the current coroutine.
     *
     * The base view factory keeps shared data and render-state arrays on the
     * instance. Cloning preserves boot-time composers and shared globals while
     * isolating per-request calls to share() and Blade render bookkeeping.
     *
     * @param  \Illuminate\Foundation\Application  $sandbox
     * @return \Illuminate\Contracts\View\Factory
     */
    protected function createViewFactory(Application $sandbox): \Illuminate\Contracts\View\Factory
    {
        /** @var \Illuminate\View\Factory $view */
        $view = clone $this->app->make('view');
        $view->setContainer($sandbox);
        $view->share('app', $sandbox);
        $view->flushState();

        return $view;
    }

    /**
     * Create an isolated log manager for the current coroutine.
     *
     * Log::shareContext() mutates worker-level state on the shared manager.
     * Reset resolved channels and shared context so each coroutine gets a
     * clean logger view while reusing the worker-level logging config.
     *
     * @param  \Illuminate\Foundation\Application  $sandbox
     * @return mixed
     */
    protected function createLogManager(Application $sandbox)
    {
        $log = clone $this->app->make('log');

        if (method_exists($log, 'setApplication')) {
            $log->setApplication($sandbox);
        }

        $this->setObjectProperty($log, 'channels', []);
        $this->setObjectProperty($log, 'sharedContext', []);

        return $log;
    }

    /**
     * Create a redirector bound to the coroutine sandbox.
     *
     * @param  \Illuminate\Foundation\Application  $sandbox
     * @return \Illuminate\Routing\Redirector
     */
    protected function createRedirector(Application $sandbox): \Illuminate\Routing\Redirector
    {
        $redirector = new \Illuminate\Routing\Redirector($sandbox->make('url'));

        if ($sandbox->bound('session.store')) {
            $redirector->setSession($sandbox->make('session.store'));
        }

        return $redirector;
    }

    /**
     * Create a response factory bound to the coroutine sandbox.
     *
     * @param  \Illuminate\Foundation\Application  $sandbox
     * @return \Illuminate\Routing\ResponseFactory
     */
    protected function createResponseFactory(Application $sandbox): \Illuminate\Routing\ResponseFactory
    {
        return new \Illuminate\Routing\ResponseFactory(
            $sandbox->make(\Illuminate\Contracts\View\Factory::class),
            $sandbox->make('redirect'),
        );
    }

    /**
     * Create an isolated URL generator for the current coroutine.
     *
     * @param  \Illuminate\Foundation\Application  $sandbox
     * @return mixed
     */
    protected function createUrlGenerator(Application $sandbox)
    {
        $url = clone $this->app->make('url');

        if (($request = $this->get('request')) instanceof Request && method_exists($url, 'setRequest')) {
            $url->setRequest($request);
        }

        if (method_exists($url, 'setSessionResolver')) {
            $url->setSessionResolver(static fn () => $sandbox->make('session'));
        }

        if (method_exists($url, 'setKeyResolver')) {
            $url->setKeyResolver(static function () use ($sandbox) {
                $config = $sandbox->make('config');

                return [$config->get('app.key'), ...($config->get('app.previous_keys') ?? [])];
            });
        }

        return $url;
    }

    /**
     * Prepare Redis configuration for a coroutine-local manager.
     *
     * @param  \Illuminate\Foundation\Application  $sandbox
     * @return array<string, mixed>
     */
    protected function createCoroutineRedisConfig(Application $sandbox): array
    {
        $redisConfig = $sandbox->make('config')->get('database.redis');

        if (! is_array($redisConfig)) {
            return [];
        }

        foreach ($redisConfig as $name => $connection) {
            if (! is_array($connection) || in_array($name, ['client', 'options', 'clusters'], true)) {
                continue;
            }

            if (($connection['persistent'] ?? false) !== true) {
                continue;
            }

            $redisConfig[$name]['persistent'] = false;
            unset($redisConfig[$name]['persistent_id']);
        }

        return $redisConfig;
    }

    /**
     * Set a protected property on an object and its parent classes.
     *
     * @param  object  $object
     * @param  string  $property
     * @param  mixed  $value
     * @return void
     */
    protected function setObjectProperty(object $object, string $property, $value): void
    {
        try {
            $reflection = new ReflectionClass($object);

            while (! $reflection->hasProperty($property) && $reflection->getParentClass()) {
                $reflection = $reflection->getParentClass();
            }

            if (! $reflection->hasProperty($property)) {
                return;
            }

            $instanceProperty = $reflection->getProperty($property);
            $instanceProperty->setAccessible(true);
            $instanceProperty->setValue($object, $value);
        } catch (ReflectionException) {
            // If the implementation changes upstream, fall back gracefully.
        }
    }

    /**
     * Get a protected property from an object and its parent classes.
     *
     * @param  object  $object
     * @param  string  $property
     * @return mixed
     */
    protected function getObjectProperty(object $object, string $property)
    {
        try {
            $reflection = new ReflectionClass($object);

            while (! $reflection->hasProperty($property) && $reflection->getParentClass()) {
                $reflection = $reflection->getParentClass();
            }

            if (! $reflection->hasProperty($property)) {
                return null;
            }

            $instanceProperty = $reflection->getProperty($property);
            $instanceProperty->setAccessible(true);

            return $instanceProperty->getValue($object);
        } catch (ReflectionException) {
            return null;
        }
    }

    /**
     * Invoke a protected method on an object when upstream does not expose it.
     *
     * @param  object  $object
     * @param  string  $method
     * @param  array<int, mixed>  $parameters
     * @return mixed
     */
    protected function invokeObjectMethod(object $object, string $method, array $parameters = [])
    {
        try {
            $reflection = new ReflectionClass($object);

            while (! $reflection->hasMethod($method) && $reflection->getParentClass()) {
                $reflection = $reflection->getParentClass();
            }

            if (! $reflection->hasMethod($method)) {
                return null;
            }

            $instanceMethod = $reflection->getMethod($method);
            $instanceMethod->setAccessible(true);

            return $instanceMethod->invokeArgs($object, $parameters);
        } catch (ReflectionException) {
            return null;
        }
    }
}
