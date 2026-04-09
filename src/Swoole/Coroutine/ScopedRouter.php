<?php

namespace Laravel\Octane\Swoole\Coroutine;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Routing\Events\Routing;

/**
 * Router clone with coroutine-local current request / route state.
 *
 * The base Laravel router stores dispatch state on mutable instance
 * properties and mutates the matched Route instance in-place. This wrapper
 * reuses the shared route collection but clones the matched Route per
 * request so concurrent coroutines do not race on router or route state.
 */
class ScopedRouter extends Router
{
    public function __construct(Router $router, Container $container)
    {
        parent::__construct($this->readProperty($router, 'events'), $container);

        $this->routes = $router->getRoutes();
        $this->middleware = $router->getMiddleware();
        $this->middlewareGroups = $router->getMiddlewareGroups();
        $this->middlewarePriority = $router->middlewarePriority;
        $this->binders = $this->readProperty($router, 'binders') ?? [];
        $this->patterns = $this->readProperty($router, 'patterns') ?? [];
        $this->groupStack = $this->readProperty($router, 'groupStack') ?? [];
        $this->implicitBindingCallback = $this->readProperty($router, 'implicitBindingCallback');
    }

    /**
     * Return the response returned by the given route.
     *
     * @param  string  $name
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function respondWithRoute($name)
    {
        $route = tap($this->cloneRoute($this->routes->getByName($name)))->bind($this->currentRequest);

        return $this->runRoute($this->currentRequest, $route);
    }

    /**
     * Find the route matching a given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Routing\Route
     */
    protected function findRoute($request)
    {
        $this->events->dispatch(new Routing($request));

        $this->current = $route = $this->cloneRoute($this->routes->match($request));

        $this->container->instance(Route::class, $route);

        return $route;
    }

    /**
     * Clone the matched route so per-request parameter and container state
     * does not mutate the shared route definitions collection.
     *
     * @param  \Illuminate\Routing\Route|null  $route
     * @return \Illuminate\Routing\Route
     */
    protected function cloneRoute(?Route $route): Route
    {
        $route = clone $route;
        $route->setRouter($this);
        $route->setContainer($this->container);

        return $route;
    }

    /**
     * Read a protected property from the base router.
     *
     * @param  object  $object
     * @param  string  $property
     * @return mixed
     */
    protected function readProperty(object $object, string $property)
    {
        $reflection = new \ReflectionClass($object);

        while (! $reflection->hasProperty($property) && $reflection->getParentClass()) {
            $reflection = $reflection->getParentClass();
        }

        if (! $reflection->hasProperty($property)) {
            return null;
        }

        $instanceProperty = $reflection->getProperty($property);
        $instanceProperty->setAccessible(true);

        return $instanceProperty->getValue($object);
    }
}
