<?php

namespace Laravel\Octane\Swoole\Coroutine;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Bridges the shared worker application to the current coroutine request.
 *
 * Concurrent coroutines cannot pin a single Request on the base container via
 * instance(). Instead the worker binds a factory that reads RequestScope from
 * coroutine Context, so Laravel code that resolves Request through the worker
 * container (event listeners, Auth, etc.) still receives the active request.
 */
class WorkerRequestBinding
{
    /**
     * @var array<int, string>
     */
    protected static array $abstracts = [
        'request',
        Request::class,
        SymfonyRequest::class,
    ];

    /**
     * Install (or refresh) the Context-aware request factory on the worker app.
     */
    public static function install(Application $app): void
    {
        foreach (static::$abstracts as $abstract) {
            if (method_exists($app, 'forgetInstance')) {
                $app->forgetInstance($abstract);
            }
        }

        $resolver = static function () {
            $scope = Context::get('octane.request_scope');

            if ($scope instanceof RequestScope && $scope->has('request')) {
                return $scope->get('request');
            }

            throw new RuntimeException(
                'Coroutine request is not available on the worker container.'
            );
        };

        $app->bind('request', $resolver);

        // Ensure class type-hints resolve through the same factory even when
        // aliases were wiped by a prior instance() call on the worker app.
        $app->alias('request', Request::class);
        $app->alias('request', SymfonyRequest::class);
    }
}
