<?php

namespace Laravel\Octane\Swoole;

use Laravel\Octane\Contracts\ServerProcessInspector as ServerProcessInspectorContract;
use Laravel\Octane\Exec;

class ServerProcessInspector implements ServerProcessInspectorContract
{
    public function __construct(
        protected SignalDispatcher $dispatcher,
        protected ServerStateFile $serverStateFile,
        protected Exec $exec,
        protected int $gracefulShutdownSeconds = 30,
    ) {
    }

    /**
     * Determine if the Swoole server process is running.
     */
    public function serverIsRunning(): bool
    {
        [
            'masterProcessId' => $masterProcessId,
            'managerProcessId' => $managerProcessId
        ] = $this->serverStateFile->read();

        return $managerProcessId
                ? $masterProcessId && $managerProcessId && $this->dispatcher->canCommunicateWith((int) $managerProcessId)
                : $masterProcessId && $this->dispatcher->canCommunicateWith((int) $masterProcessId);
    }

    /**
     * Reload the Swoole workers.
     */
    public function reloadServer(): void
    {
        [
            'masterProcessId' => $masterProcessId,
        ] = $this->serverStateFile->read();

        $this->dispatcher->signal((int) $masterProcessId, SIGUSR1);
    }

    /**
     * Stop the Swoole server.
     */
    public function stopServer(): bool
    {
        [
            'masterProcessId' => $masterProcessId,
            'managerProcessId' => $managerProcessId,
            'state' => $state,
        ] = $this->serverStateFile->read();

        $starterProcessId = (int) ($state['starterProcessId'] ?? 0);

        if ($this->shouldDelegateStopToStarterProcess($starterProcessId, (int) $masterProcessId)) {
            $this->dispatcher->signal($starterProcessId, SIGTERM);

            if ($this->waitForProcessesToStop($this->processIds($masterProcessId, $managerProcessId), $this->gracefulShutdownSeconds)) {
                return true;
            }
        }

        $processIds = $this->processIds($masterProcessId, $managerProcessId);
        $masterProcessId = (int) $masterProcessId;

        foreach ($processIds as $processId) {
            $this->dispatcher->signal($processId, SIGTERM);
        }

        $nonMasterProcessIds = array_values(array_filter(
            $processIds,
            fn (int $processId) => $processId !== $masterProcessId
        ));

        if ($this->waitForProcessesToStop($nonMasterProcessIds, $this->gracefulShutdownSeconds)
            && (! $masterProcessId || ! $this->dispatcher->canCommunicateWith($masterProcessId))) {
            return true;
        }

        if ($masterProcessId && $this->dispatcher->canCommunicateWith($masterProcessId)) {
            $this->dispatcher->signal($masterProcessId, SIGINT);

            if ($this->waitForProcessesToStop($this->processIds($masterProcessId, $managerProcessId), min(5, $this->gracefulShutdownSeconds))) {
                return true;
            }
        }

        foreach ($this->processIds($masterProcessId, $managerProcessId) as $processId) {
            if ($this->dispatcher->canCommunicateWith($processId)) {
                $this->dispatcher->signal($processId, SIGKILL);
            }
        }

        return true;
    }

    protected function shouldDelegateStopToStarterProcess(int $starterProcessId, int $masterProcessId): bool
    {
        return $starterProcessId > 0
            && $masterProcessId > 0
            && $starterProcessId !== getmypid()
            && $this->dispatcher->canCommunicateWith($starterProcessId)
            && $this->processParentId($masterProcessId) === $starterProcessId;
    }

    protected function processParentId(int $processId): ?int
    {
        $output = $this->exec->run('ps -o ppid= -p '.$processId);
        $parentProcessId = trim($output[0] ?? '');

        return $parentProcessId === '' ? null : (int) $parentProcessId;
    }

    protected function processIds($masterProcessId, $managerProcessId): array
    {
        $processIds = array_filter([(int) $masterProcessId, (int) $managerProcessId]);

        if ($managerProcessId) {
            $processIds = array_merge($processIds, array_map(
                'intval',
                $this->exec->run('pgrep -P '.(int) $managerProcessId)
            ));
        }

        return array_values(array_unique(array_filter($processIds)));
    }

    protected function waitForProcessesToStop(array $processIds, int $seconds): bool
    {
        $deadline = microtime(true) + max(0, $seconds);

        do {
            $running = array_filter(
                $processIds,
                fn (int $processId) => $this->dispatcher->canCommunicateWith($processId)
            );

            if ($running === []) {
                return true;
            }

            if ($seconds <= 0) {
                return false;
            }

            usleep(100000);
        } while (microtime(true) < $deadline);

        return false;
    }
}
