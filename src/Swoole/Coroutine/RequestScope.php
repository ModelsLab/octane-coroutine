<?php

namespace Laravel\Octane\Swoole\Coroutine;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use ReflectionClass;
use ReflectionException;

/**
 * Lightweight per-request state storage for coroutine isolation.
 *
 * Instead of cloning the entire Application (~3-5MB) per request,
 * this class holds only the ~10 request-scoped bindings that need
 * per-coroutine isolation (request, session, auth, config, url, cookie).
 *
 * Process-scoped bindings (router, db, cache, etc.) remain on the
 * shared base Application and are accessed directly.
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
        unset($this->bindings[$key]);
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

        $resolved = match ($key) {
            'auth' => $this->createAuthManager($sandbox),
            'auth.driver' => $this->createAuthDriver($sandbox),
            'config' => $this->cloneConfig(),
            'cookie' => $this->createCookieJar(),
            'redirect' => $this->createRedirector($sandbox),
            'request' => $this->get('request'),
            'session' => $this->createSessionManager($sandbox),
            'session.store' => $this->createSessionStore($sandbox),
            'url' => $this->createUrlGenerator($sandbox),
            \Illuminate\Routing\Contracts\CallableDispatcher::class => new \Illuminate\Routing\CallableDispatcher($sandbox),
            \Illuminate\Routing\Contracts\ControllerDispatcher::class => new \Illuminate\Routing\ControllerDispatcher($sandbox),
            \Illuminate\Contracts\Routing\ResponseFactory::class => $this->createResponseFactory($sandbox),
            default => null,
        };

        if ($resolved !== null || $key === 'request') {
            $this->bindings[$key] = $resolved;
        }

        return $resolved;
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
        $this->bindings = [];
        $this->configCloned = false;
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

        if (method_exists($auth, 'forgetGuards')) {
            $auth->forgetGuards();
        }

        return $auth;
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
}
