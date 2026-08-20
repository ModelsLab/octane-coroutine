<?php

namespace Laravel\Octane;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Cache\OctaneArrayStore;
use Laravel\Octane\Cache\OctaneStore;
use Laravel\Octane\Contracts\DispatchesCoroutines;
use Laravel\Octane\Events\TickReceived;
use Laravel\Octane\Exceptions\DdException;
use Laravel\Octane\Exceptions\TaskException;
use Laravel\Octane\Exceptions\TaskTimeoutException;
use Laravel\Octane\Facades\Octane as OctaneFacade;
use Laravel\Octane\FrankenPhp\ServerProcessInspector as FrankenPhpServerProcessInspector;
use Laravel\Octane\FrankenPhp\ServerStateFile as FrankenPhpServerStateFile;
use Laravel\Octane\RoadRunner\ServerProcessInspector as RoadRunnerServerProcessInspector;
use Laravel\Octane\RoadRunner\ServerStateFile as RoadRunnerServerStateFile;
use Laravel\Octane\Swoole\Coroutine\LivewireCoroutineMutex;
use Laravel\Octane\Swoole\ServerProcessInspector as SwooleServerProcessInspector;
use Laravel\Octane\Swoole\ServerStateFile as SwooleServerStateFile;
use Laravel\Octane\Swoole\SignalDispatcher;
use Laravel\Octane\Swoole\SwooleCoroutineDispatcher;
use Laravel\Octane\Swoole\SwooleTaskDispatcher;

class OctaneServiceProvider extends ServiceProvider
{
    /**
     * Register Octane's services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/octane.php', 'octane');

        $this->bindListeners();

        $this->app->singleton('octane', Octane::class);
        $this->app->singleton(LivewireCoroutineMutex::class);

        $this->app->singleton('db', function ($app) {
            return new \Laravel\Octane\Swoole\Database\DatabaseManager($app, $app['db.factory']);
        });

        // The custom mysql Connection carries two independently-gated
        // features: string integer bindings (kills MySQL 9.0's per-execute
        // reprepare; OCTANE_MYSQL_STRING_BINDINGS=false) and the prepared-
        // statement cache (OCTANE_MYSQL_STMT_CACHE=false). Install it when
        // either is on - the class checks each flag itself, so disabling one
        // never silently disables the other.
        $stringBindings = filter_var(config('octane.mysql_string_bindings', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
        $statementCache = filter_var(config('octane.mysql_statement_cache', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;

        if ($stringBindings || $statementCache) {
            \Illuminate\Database\Connection::resolverFor('mysql', function ($connection, $database, $prefix, $config) {
                return new \Laravel\Octane\Swoole\Database\MySqlStringBindingConnection($connection, $database, $prefix, $config);
            });
        }

        $this->bindCoroutineRedisManager();

        $this->app->bind(RoadRunnerServerProcessInspector::class, function ($app) {
            return new RoadRunnerServerProcessInspector(
                $app->make(RoadRunnerServerStateFile::class),
                new SymfonyProcessFactory,
                new PosixExtension,
            );
        });

        $this->app->bind(RoadRunnerServerStateFile::class, function ($app) {
            return new RoadRunnerServerStateFile($app['config']->get(
                'octane.state_file',
                storage_path('logs/octane-server-state.json')
            ));
        });

        $this->app->bind(SwooleServerProcessInspector::class, function ($app) {
            return new SwooleServerProcessInspector(
                $app->make(SignalDispatcher::class),
                $app->make(SwooleServerStateFile::class),
                $app->make(Exec::class),
                (int) $app['config']->get('octane.swoole.shutdown_timeout', 30),
            );
        });

        $this->app->bind(SwooleServerStateFile::class, function ($app) {
            return new SwooleServerStateFile($app['config']->get(
                'octane.state_file',
                storage_path('logs/octane-server-state.json')
            ));
        });

        $this->app->bind(FrankenPhpServerProcessInspector::class, function ($app) {
            return new FrankenPhpServerProcessInspector(
                $app->make(FrankenPhpServerStateFile::class)
            );
        });

        $this->app->bind(FrankenPhpServerStateFile::class, function ($app) {
            return new FrankenPhpServerStateFile($app['config']->get(
                'octane.state_file',
                storage_path('logs/octane-server-state.json')
            ));
        });

        $this->app->bind(DispatchesCoroutines::class, function ($app) {
            return class_exists('Swoole\Http\Server')
                        ? new SwooleCoroutineDispatcher($app->bound('Swoole\Http\Server'))
                        : $app->make(SequentialCoroutineDispatcher::class);
        });
    }

    /**
     * Swap the framework's Redis manager for a coroutine-aware one.
     *
     * Swoole binds a hooked phpredis socket to the coroutine that first reads
     * from it. A second coroutine issuing a command on that same connection
     * makes Swoole raise "Socket#N has already been bound to another
     * coroutine#M" from the scheduler, which userland try/catch cannot contain,
     * so the worker dies outright.
     *
     * Boot-time singletons are the exposure: RequestScope isolates whatever
     * resolves `redis` during a request, but an object built at boot keeps the
     * worker-level manager forever. The framework's own RateLimiter singleton
     * is one of those. Making the worker-level manager coroutine-aware fixes
     * every such holder that re-resolves its connection per operation, which
     * the cache RedisStore does.
     *
     * Registered as an extender because Illuminate's RedisServiceProvider is
     * deferred: a plain singleton() here would be overwritten the moment the
     * deferred provider loads.
     *
     * @return void
     */
    protected function bindCoroutineRedisManager()
    {
        $this->app->extend('redis', function ($redis, $app) {
            if ($redis instanceof \Laravel\Octane\Swoole\Redis\CoroutineRedisManager) {
                return $redis;
            }

            // Leave customized managers alone; replacing them would silently
            // drop whatever behaviour the application layered on. Those apps
            // still get per-coroutine isolation through RequestScope.
            if (! $redis instanceof \Illuminate\Redis\RedisManager
                || $redis::class !== \Illuminate\Redis\RedisManager::class) {
                return $redis;
            }

            $config = $app->make('config')->get('database.redis', []);

            return new \Laravel\Octane\Swoole\Redis\CoroutineRedisManager(
                $app,
                \Illuminate\Support\Arr::pull($config, 'client', 'phpredis'),
                $config
            );
        });
    }

    /**
     * Bootstrap Octane's services.
     *
     * @return void
     */
    public function boot()
    {
        $dispatcher = $this->app[Dispatcher::class];

        foreach ($this->app['config']->get('octane.listeners', []) as $event => $listeners) {
            foreach (array_filter(array_unique($listeners)) as $listener) {
                $dispatcher->listen($event, $listener);
            }
        }

        $this->registerCacheDriver();
        $this->registerCommands();
        $this->registerHttpTaskHandlingRoutes();
        $this->registerPublishing();
        $this->registerLivewireCoroutineMutex();
    }

    /**
     * Bind the Octane event listeners in the container.
     *
     * @return void
     */
    protected function bindListeners()
    {
        $this->app->singleton(Listeners\CollectGarbage::class);
        $this->app->singleton(Listeners\CreateConfigurationSandbox::class);
        $this->app->singleton(Listeners\CreateUrlGeneratorSandbox::class);
        $this->app->singleton(Listeners\DisconnectFromDatabases::class);
        $this->app->singleton(Listeners\EnforceRequestScheme::class);
        $this->app->singleton(Listeners\EnsureRequestServerPortMatchesScheme::class);
        $this->app->singleton(Listeners\EnsureUploadedFilesAreValid::class);
        $this->app->singleton(Listeners\EnsureUploadedFilesCanBeMoved::class);
        $this->app->singleton(Listeners\FlushAuthenticationState::class);
        $this->app->singleton(Listeners\FlushQueuedCookies::class);
        $this->app->singleton(Listeners\FlushSessionState::class);
        $this->app->singleton(Listeners\FlushTemporaryContainerInstances::class);
        $this->app->singleton(Listeners\GiveNewApplicationInstanceToAuthorizationGate::class);
        $this->app->singleton(Listeners\GiveNewApplicationInstanceToBroadcastManager::class);
        $this->app->singleton(Listeners\GiveNewApplicationInstanceToHttpKernel::class);
        $this->app->singleton(Listeners\GiveNewApplicationInstanceToLogManager::class);
        $this->app->singleton(Listeners\GiveNewApplicationInstanceToMailManager::class);
        $this->app->singleton(Listeners\GiveNewApplicationInstanceToNotificationChannelManager::class);
        $this->app->singleton(Listeners\GiveNewApplicationInstanceToPipelineHub::class);
        $this->app->singleton(Listeners\GiveNewApplicationInstanceToQueueManager::class);
        $this->app->singleton(Listeners\GiveNewApplicationInstanceToRouter::class);
        $this->app->singleton(Listeners\GiveNewApplicationInstanceToValidationFactory::class);
        $this->app->singleton(Listeners\GiveNewApplicationInstanceToViewFactory::class);
        $this->app->singleton(Listeners\GiveNewRequestInstanceToApplication::class);
        $this->app->singleton(Listeners\GiveNewRequestInstanceToPaginator::class);
        $this->app->singleton(Listeners\PrepareInertiaForNextOperation::class);
        $this->app->singleton(Listeners\PrepareLivewireForNextOperation::class);
        $this->app->singleton(Listeners\PrepareScoutForNextOperation::class);
        $this->app->singleton(Listeners\PrepareSocialiteForNextOperation::class);
        $this->app->singleton(Listeners\ReportException::class);
        $this->app->singleton(Listeners\StopWorkerIfNecessary::class);
    }

    protected function registerLivewireCoroutineMutex(): void
    {
        $this->app->booted(function (): void {
            if (! function_exists('Livewire\\before') || ! function_exists('Livewire\\after')) {
                return;
            }

            \Livewire\before('render', function (): void {
                $this->app->make(LivewireCoroutineMutex::class)->acquire();
            });

            \Livewire\after('render', function () {
                return function ($html) {
                    $this->app->make(LivewireCoroutineMutex::class)->release();

                    return $html;
                };
            });
        });
    }

    /**
     * Register the Octane cache driver.
     *
     * @return void
     */
    protected function registerCacheDriver()
    {
        if (empty($this->app['config']['octane.cache'])) {
            return;
        }

        $store = $this->app->bound('octane.cacheTable')
                        ? new OctaneStore($this->app['octane.cacheTable'])
                        : new OctaneArrayStore;

        Event::listen(TickReceived::class, fn () => $store->refreshIntervalCaches());

        Cache::extend('octane', fn () => Cache::repository($store));
    }

    /**
     * Register the commands offered by Octane.
     *
     * @return void
     */
    protected function registerCommands()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\InstallCommand::class,
                Commands\StartCommand::class,
                Commands\StartRoadRunnerCommand::class,
                Commands\StartSwooleCommand::class,
                Commands\StartFrankenPhpCommand::class,
                Commands\ReloadCommand::class,
                Commands\StatusCommand::class,
                Commands\StopCommand::class,
            ]);
        }
    }

    /**
     * Register the Octane routes that handle tasks from invokers not in a Server context.
     *
     * @return void
     */
    protected function registerHttpTaskHandlingRoutes()
    {
        OctaneFacade::route('POST', '/octane/resolve-tasks', function (Request $request) {
            try {
                return new Response(serialize((new SwooleTaskDispatcher)->resolve(
                    unserialize(Crypt::decryptString($request->input('tasks'))),
                    $request->input('wait')
                )), 200);
            } catch (DecryptException) {
                return new Response('', 403);
            } catch (TaskException|DdException) {
                return new Response('', 500);
            } catch (TaskTimeoutException) {
                return new Response('', 504);
            }
        });

        OctaneFacade::route('POST', '/octane/dispatch-tasks', function (Request $request) {
            try {
                (new SwooleTaskDispatcher)->dispatch(
                    unserialize(Crypt::decryptString($request->input('tasks'))),
                );
            } catch (DecryptException) {
                return new Response('', 403);
            }

            return new Response('', 200);
        });
    }

    /**
     * Register Octane's publishing.
     *
     * @return void
     */
    protected function registerPublishing()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/octane.php' => config_path('octane.php'),
            ], 'octane-config');
        }
    }
}
