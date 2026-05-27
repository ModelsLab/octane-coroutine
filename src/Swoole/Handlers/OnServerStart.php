<?php

namespace Laravel\Octane\Swoole\Handlers;

use Laravel\Octane\Swoole\Actions\EnsureRequestsDontExceedMaxExecutionTime;
use Laravel\Octane\Swoole\ServerStateFile;
use Laravel\Octane\Swoole\SwooleExtension;
use Swoole\Timer;

class OnServerStart
{
    public function __construct(
        protected ServerStateFile $serverStateFile,
        protected SwooleExtension $extension,
        protected string $appName,
        protected int $maxExecutionTime,
        protected $timerTable,
        protected bool $shouldTick = true,
        protected bool $shouldSetProcessName = true,
        protected int|string|null $timeoutFallbackSignal = SIGTERM
    ) {
        $this->timeoutFallbackSignal = EnsureRequestsDontExceedMaxExecutionTime::normalizeFallbackSignal($this->timeoutFallbackSignal);
    }

    /**
     * Handle the "start" Swoole event.
     *
     * @param  \Swoole\Http\Server  $server
     * @return void
     */
    public function __invoke($server)
    {
        $this->serverStateFile->writeProcessIds(
            $server->master_pid,
            $server->manager_pid
        );

        if ($this->shouldSetProcessName) {
            $this->extension->setProcessName($this->appName, 'master process');
        }

        // Following Hyperf/Swoole best practices: only create tick timer if both
        // tick is enabled AND task workers are available to handle the ticks
        if ($this->shouldTick) {
            $taskWorkerNum = $server->setting['task_worker_num'] ?? 0;
            
            if ($taskWorkerNum > 0) {
                Timer::tick(1000, function () use ($server) {
                    $server->task('octane-tick');
                });
            } else {
                // Log warning if tick is enabled but no task workers available
                error_log(
                    '⚠️  Octane tick is enabled but task_worker_num is 0. ' .
                    'Tick events will not be dispatched. ' .
                    'Either disable tick in config/octane.php or start with --task-workers=1'
                );
            }
        }

        if ($this->maxExecutionTime > 0) {
            Timer::tick(1000, function () use ($server) {
                (new EnsureRequestsDontExceedMaxExecutionTime(
                    $this->extension, $this->timerTable, $this->maxExecutionTime, $server, $this->timeoutFallbackSignal
                ))();
            });
        }
    }
}
