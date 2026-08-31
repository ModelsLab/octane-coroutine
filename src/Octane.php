<?php

namespace Laravel\Octane;

use Exception;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\DevCommands;
use Laravel\Octane\Swoole\WorkerState;
use Swoole\Http\Server;
use Swoole\Table;
use Throwable;

class Octane
{
    use Concerns\ProvidesConcurrencySupport;
    use Concerns\ProvidesDefaultConfigurationOptions;
    use Concerns\ProvidesRouting;
    use Concerns\RegistersTickHandlers;

    /**
     * Get a Swoole table instance.
     */
    public function table(string $table): Table
    {
        if (! app()->bound(Server::class)) {
            throw new Exception('Tables may only be accessed when using the Swoole server.');
        }

        $tables = app(WorkerState::class)->tables;

        if (! isset($tables[$table])) {
            throw new Exception("Swoole table [{$table}] has not been configured.");
        }

        return $tables[$table];
    }

    /**
     * Format an exception to a string that should be returned to the client.
     */
    public static function formatExceptionForClient(Throwable $e, bool $debug = false): string
    {
        return $debug ? (string) $e : 'Internal server error.';
    }

    /**
     * Write an error message to STDERR or to the SAPI logger if not in CLI mode.
     */
    public static function writeError(string $message): void
    {
        if (defined('STDERR')) {
            fwrite(STDERR, $message.PHP_EOL);

            return;
        }

        error_log($message, 4);
    }

    /**
     * Register the Octane dev commands.
     *
     * Laravel 13's "artisan dev" command runs a set of registered processes.
     * Registering Octane as the "server" process replaces the default
     * "artisan serve" so the dev command boots Octane instead.
     */
    public static function registerDevCommands(): void
    {
        if (! class_exists(DevCommands::class)) {
            return;
        }

        // DevCommands reaches for the container itself, so only register once a
        // real application is bound. Octane builds sandbox containers of its
        // own, and those are not always in place when this provider registers.
        $app = Container::getInstance();

        if (! $app instanceof Application) {
            return;
        }

        DevCommands::artisan('octane:start --watch', 'server');
    }
}
