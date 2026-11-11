<?php

namespace Laravel\Octane\Swoole\Coroutine;

/**
 * Resolves Swoole runtime hook flags for Octane workers.
 *
 * SWOOLE_HOOK_ALL includes FILE/STDIO hooks that deadlock ZipArchive,
 * SplFileObject, PhpSpreadsheet, and Livewire temporary uploads. Those
 * hooks stay off unless explicitly re-enabled in config.
 */
class RuntimeHooks
{
    /**
     * Enable coroutine runtime hooks using the resolved flag mask.
     *
     * @param  array<string, mixed>  $swooleConfig
     */
    public static function enable(array $swooleConfig = []): void
    {
        if (! \extension_loaded('swoole') || ! \class_exists(\Swoole\Runtime::class)) {
            return;
        }

        // When the Swoole server runs without request coroutines, arming hooks
        // is unnecessary for uploads and can interact badly with blocking I/O.
        $options = is_array($swooleConfig['options'] ?? null) ? $swooleConfig['options'] : [];
        if (array_key_exists('enable_coroutine', $options) && ! $options['enable_coroutine']) {
            return;
        }

        \Swoole\Runtime::enableCoroutine(static::flags($swooleConfig));
    }

    /**
     * @param  array<string, mixed>  $swooleConfig
     */
    public static function flags(array $swooleConfig = []): int
    {
        if (isset($swooleConfig['hook_flags']) && is_int($swooleConfig['hook_flags'])) {
            return $swooleConfig['hook_flags'];
        }

        $flags = \defined('SWOOLE_HOOK_ALL') ? SWOOLE_HOOK_ALL : 0;

        if (! ($swooleConfig['hook_file'] ?? false) && \defined('SWOOLE_HOOK_FILE')) {
            $flags &= ~SWOOLE_HOOK_FILE;
        }

        if (! ($swooleConfig['hook_stdio'] ?? false) && \defined('SWOOLE_HOOK_STDIO')) {
            $flags &= ~SWOOLE_HOOK_STDIO;
        }

        return $flags;
    }
}
