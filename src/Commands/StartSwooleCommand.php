<?php

namespace Laravel\Octane\Commands;

use Illuminate\Support\Str;
use Laravel\Octane\Swoole\ServerProcessInspector;
use Laravel\Octane\Swoole\ServerStateFile;
use Laravel\Octane\Swoole\SwooleExtension;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'octane:swoole')]
class StartSwooleCommand extends Command implements SignalableCommandInterface
{
    use Concerns\InteractsWithEnvironmentVariables, Concerns\InteractsWithServers;

    /**
     * The command's signature.
     *
     * @var string
     */
    public $signature = 'octane:swoole
                    {--host= : The IP address the server should bind to}
                    {--port= : The port the server should be available on}
                    {--workers=auto : The number of workers that should be available to handle requests}
                    {--task-workers=auto : The number of task workers that should be available to handle tasks}
                    {--max-requests=500 : The number of requests to process before reloading the server}
                    {--pool= : The number of application instances in the coroutine pool}
                    {--watch : Automatically reload the server when the application is modified}
                    {--poll : Use file system polling while watching in order to watch files over a network}';

    /**
     * The command's description.
     *
     * @var string
     */
    public $description = 'Start the Octane Swoole server';

    /**
     * Indicates whether the command should be shown in the Artisan command list.
     *
     * @var bool
     */
    protected $hidden = true;

    /**
     * Handle the command.
     *
     * @return int
     */
    public function handle(
        ServerProcessInspector $inspector,
        ServerStateFile $serverStateFile,
        SwooleExtension $extension
    ) {
        if (! $extension->isInstalled()) {
            $this->components->error('The Swoole extension is missing.');

            return 1;
        }

        $this->ensurePortIsAvailable();

        if ($inspector->serverIsRunning()) {
            $this->components->error('Server is already running.');

            return 1;
        }

        if (config('octane.swoole.ssl', false) === true && ! defined('SWOOLE_SSL')) {
            $this->components->error('You must configure Swoole with `--enable-openssl` to support ssl.');

            return 1;
        }

        $this->writeServerStateFile($serverStateFile, $extension);

        $this->forgetEnvironmentVariables();

        $server = tap(new Process([
            (new PhpExecutableFinder)->find(),
            ...config('octane.swoole.php_options', []),
            config('octane.swoole.command', 'swoole-server'),
            $serverStateFile->path(),
        ], realpath(__DIR__.'/../../bin'), [
            'APP_ENV' => app()->environment(),
            'APP_BASE_PATH' => base_path(),
            'LARAVEL_OCTANE' => 1,
        ]))->start();

        return $this->runServer($server, $inspector, 'swoole');
    }

    /**
     * Write the Swoole server state file.
     *
     * @return void
     */
    protected function writeServerStateFile(
        ServerStateFile $serverStateFile,
        SwooleExtension $extension
    ) {
        $octaneConfig = config('octane');
        
        // Override pool size if specified via CLI
        if ($this->option('pool')) {
            $octaneConfig['swoole']['pool']['size'] = (int) $this->option('pool');
        }
        
        $serverStateFile->writeState([
            'appName' => config('app.name', 'Laravel'),
            'host' => $this->getHost(),
            'port' => $this->getPort(),
            'workers' => $this->workerCount($extension),
            'taskWorkers' => $this->taskWorkerCount($extension),
            'maxRequests' => $this->option('max-requests'),
            'publicPath' => public_path(),
            'storagePath' => storage_path(),
            'defaultServerOptions' => $this->defaultServerOptions($extension),
            'octaneConfig' => $octaneConfig,
        ]);
    }

    /**
     * Get the default Swoole server options.
     *
     * @return array
     */
    protected function defaultServerOptions(SwooleExtension $extension)
    {
        return [
            'enable_coroutine' => true,
            'daemonize' => false,
            'log_file' => storage_path('logs/swoole_http.log'),
            'log_level' => app()->environment('local') ? SWOOLE_LOG_INFO : SWOOLE_LOG_ERROR,
            'max_request' => $this->option('max-requests'),
            'package_max_length' => 10 * 1024 * 1024,
            'reactor_num' => $this->workerCount($extension),
            'reload_async' => true,
            'max_wait_time' => 60,
            'backlog' => 4096,
            'max_conn' => 10000,
            'open_tcp_nodelay' => true,
            'send_yield' => true,
            'socket_buffer_size' => 10 * 1024 * 1024,
            'task_max_request' => $this->option('max-requests'),
            'task_worker_num' => $this->taskWorkerCount($extension),
            'worker_num' => $this->workerCount($extension),
        ];
    }

    /**
     * Get the number of workers that should be started.
     *
     * @return int
     */
    protected function workerCount(SwooleExtension $extension)
    {
        return $this->option('workers') === 'auto'
                    ? $extension->cpuCount()
                    : $this->option('workers');
    }

    /**
     * Get the number of task workers that should be started.
     *
     * Following Hyperf/Swoole best practices, task workers are disabled by
     * default (0) since most applications don't need them. Task workers are
     * only required if:
     * 1. You explicitly use $server->task() to dispatch async tasks
     * 2. You enable tick timers (config('octane.swoole.tick'))
     *
     * If tick is enabled, we use 1 task worker (not CPU count) to handle
     * tick events efficiently without creating excessive worker overhead.
     *
     * @return int
     */
    protected function taskWorkerCount(SwooleExtension $extension)
    {
        $taskWorkers = $this->option('task-workers');
        
        // If explicitly set, use that value
        if ($taskWorkers !== 'auto') {
            return (int) $taskWorkers;
        }
        
        // For 'auto': if tick is enabled, use 1 worker; otherwise 0
        $tickEnabled = config('octane.swoole.tick', false);
        
        return $tickEnabled ? 1 : 0;
    }

    /**
     * Write the server process output ot the console.
     *
     * @param  \Symfony\Component\Process\Process  $server
     * @return void
     */
    protected function writeServerOutput($server)
    {
        [$output, $errorOutput] = $this->getServerOutput($server);

        Str::of($output)
            ->explode("\n")
            ->filter()
            ->each(fn ($output) => is_array($stream = json_decode($output, true))
                ? $this->handleStream($stream)
                : $this->components->info($output)
            );

        Str::of($errorOutput)
            ->explode("\n")
            ->filter()
            ->groupBy(fn ($output) => $output)
            ->each(function ($group) {
                is_array($stream = json_decode($output = $group->first(), true)) && isset($stream['type'])
                    ? $this->handleStream($stream)
                    : $this->raw($output);
            });
    }

    /**
     * Stop the server.
     *
     * @return void
     */
    protected function stopServer()
    {
        $this->callSilent('octane:stop', [
            '--server' => 'swoole',
        ]);
    }
}
