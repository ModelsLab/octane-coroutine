<?php

namespace Laravel\Octane\Concerns;

trait ProvidesDefaultConfigurationOptions
{
    /**
     * Determine if the runtime is using the Swoole coroutine application proxy.
     */
    protected static function usingSwooleCoroutineMode(): bool
    {
        return env('OCTANE_SERVER', 'roadrunner') === 'swoole' && extension_loaded('swoole');
    }

    /**
     * Determine if a listener should run once at worker boot in coroutine mode.
     */
    protected static function isCoroutineBootOnlyListener(string $listener): bool
    {
        return str_starts_with($listener, 'Laravel\\Octane\\Listeners\\GiveNewApplicationInstanceTo');
    }

    /**
     * Get the listeners that will prepare the Laravel application for a new request.
     */
    public static function prepareApplicationForNextRequest(): array
    {
        return [
            \Laravel\Octane\Listeners\FlushLocaleState::class,
            \Laravel\Octane\Listeners\FlushQueuedCookies::class,
            \Laravel\Octane\Listeners\FlushSessionState::class,
            \Laravel\Octane\Listeners\FlushAuthenticationState::class,
            \Laravel\Octane\Listeners\EnforceRequestScheme::class,
            \Laravel\Octane\Listeners\EnsureRequestServerPortMatchesScheme::class,
            \Laravel\Octane\Listeners\GiveNewRequestInstanceToApplication::class,
            \Laravel\Octane\Listeners\GiveNewRequestInstanceToPaginator::class,
        ];
    }

    /**
     * Get the listeners that will prepare the Laravel application for a new operation.
     */
    public static function prepareApplicationForNextOperation(): array
    {
        $listeners = static::prepareApplicationForNextOperationListeners();

        if (! static::usingSwooleCoroutineMode()) {
            return $listeners;
        }

        return array_values(array_filter(
            $listeners,
            fn (string $listener) => ! static::isCoroutineBootOnlyListener($listener)
        ));
    }

    /**
     * Get the listeners that should run once at worker boot in coroutine mode.
     */
    public static function prepareApplicationForCoroutineBoot(): array
    {
        if (! static::usingSwooleCoroutineMode()) {
            return [];
        }

        return array_values(array_filter(
            static::prepareApplicationForNextOperationListeners(),
            fn (string $listener) => static::isCoroutineBootOnlyListener($listener)
        ));
    }

    /**
     * Get the full operation listener list before coroutine-mode filtering.
     */
    protected static function prepareApplicationForNextOperationListeners(): array
    {
        return [
            \Laravel\Octane\Listeners\CreateConfigurationSandbox::class,
            \Laravel\Octane\Listeners\CreateUrlGeneratorSandbox::class,
            \Laravel\Octane\Listeners\GiveNewApplicationInstanceToAuthorizationGate::class,
            \Laravel\Octane\Listeners\GiveNewApplicationInstanceToBroadcastManager::class,
            \Laravel\Octane\Listeners\GiveNewApplicationInstanceToDatabaseManager::class,
            \Laravel\Octane\Listeners\GiveNewApplicationInstanceToDatabaseSessionHandler::class,
            \Laravel\Octane\Listeners\GiveNewApplicationInstanceToFilesystemManager::class,
            \Laravel\Octane\Listeners\GiveNewApplicationInstanceToHttpKernel::class,
            \Laravel\Octane\Listeners\GiveNewApplicationInstanceToLogManager::class,
            \Laravel\Octane\Listeners\GiveNewApplicationInstanceToMailManager::class,
            \Laravel\Octane\Listeners\GiveNewApplicationInstanceToNotificationChannelManager::class,
            \Laravel\Octane\Listeners\GiveNewApplicationInstanceToPipelineHub::class,
            \Laravel\Octane\Listeners\GiveNewApplicationInstanceToCacheManager::class,
            \Laravel\Octane\Listeners\GiveNewApplicationInstanceToSessionManager::class,
            \Laravel\Octane\Listeners\GiveNewApplicationInstanceToQueueManager::class,
            \Laravel\Octane\Listeners\GiveNewApplicationInstanceToRouter::class,
            \Laravel\Octane\Listeners\GiveNewApplicationInstanceToValidationFactory::class,
            \Laravel\Octane\Listeners\GiveNewApplicationInstanceToViewFactory::class,
            \Laravel\Octane\Listeners\FlushDatabaseRecordModificationState::class,
            \Laravel\Octane\Listeners\FlushDatabaseQueryLog::class,
            \Laravel\Octane\Listeners\RefreshQueryDurationHandling::class,
            \Laravel\Octane\Listeners\FlushArrayCache::class,
            \Laravel\Octane\Listeners\FlushLogContext::class,
            \Laravel\Octane\Listeners\FlushMonologState::class,
            \Laravel\Octane\Listeners\FlushStrCache::class,
            \Laravel\Octane\Listeners\FlushTranslatorCache::class,
            \Laravel\Octane\Listeners\FlushVite::class,

            // First-Party Packages...
            \Laravel\Octane\Listeners\PrepareInertiaForNextOperation::class,
            \Laravel\Octane\Listeners\PrepareLivewireForNextOperation::class,
            \Laravel\Octane\Listeners\PrepareScoutForNextOperation::class,
            \Laravel\Octane\Listeners\PrepareSocialiteForNextOperation::class,
        ];
    }

    /**
     * Get the container bindings / services that should be pre-resolved by default.
     */
    public static function defaultServicesToWarm(): array
    {
        return [
            'auth',
            'cache',
            'cache.store',
            'config',
            'cookie',
            'db',
            'db.factory',
            'db.transactions',
            'encrypter',
            'files',
            'hash',
            'log',
            'router',
            'routes',
            'session',
            'session.store',
            'translator',
            'url',
            'view',
        ];
    }
}
