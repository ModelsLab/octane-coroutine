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
        protected int|string|null $timeoutFallbackSignal = SIGTERM,
        protected int $timeoutSweepIntervalMs = 5000
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
            // One line per boot so production can never silently run without
            // request-timeout enforcement again - chasing the 210s stalls
            // burned hours on exactly the question this answers.
            error_log("⏱️ Timeout sweep armed: max_execution_time={$this->maxExecutionTime}s, interval={$this->timeoutSweepIntervalMs}ms");

            Timer::tick($this->timeoutSweepIntervalMs, function () use ($server) {
                (new EnsureRequestsDontExceedMaxExecutionTime(
                    $this->extension, $this->timerTable, $this->maxExecutionTime, $server, $this->timeoutFallbackSignal
                ))();
            });
        } else {
            error_log('⚠️ Timeout sweep DISABLED: max_execution_time resolved to 0 - requests can run unbounded');
        }
    }
}
