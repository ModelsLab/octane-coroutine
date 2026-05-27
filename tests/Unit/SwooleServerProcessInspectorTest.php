<?php

namespace Tests\Unit;

use Laravel\Octane\Exec;
use Laravel\Octane\Swoole\ServerProcessInspector;
use Laravel\Octane\Swoole\ServerStateFile;
use Laravel\Octane\Swoole\SignalDispatcher;
use Laravel\Octane\Swoole\SwooleExtension;
use PHPUnit\Framework\TestCase;

class SwooleServerProcessInspectorTest extends TestCase
{
    public function test_stop_server_uses_sigterm_when_processes_exit_gracefully(): void
    {
        $stateFile = $this->stateFile(100, 200);
        $dispatcher = new RecordingSignalDispatcher();
        $dispatcher->running = [100 => true, 200 => true, 201 => true, 202 => true];
        $dispatcher->stopAfterSignal = SIGTERM;

        $inspector = new ServerProcessInspector(
            $dispatcher,
            $stateFile,
            new FakeExec(['201', '202']),
            1,
        );

        $this->assertTrue($inspector->stopServer());
        $this->assertSame([
            [100, SIGTERM],
            [200, SIGTERM],
            [201, SIGTERM],
            [202, SIGTERM],
        ], $dispatcher->signals);
    }

    public function test_stop_server_only_uses_sigkill_after_grace_period_expires(): void
    {
        $stateFile = $this->stateFile(100, 200);
        $dispatcher = new RecordingSignalDispatcher();
        $dispatcher->running = [100 => true, 200 => true, 201 => true];

        $inspector = new ServerProcessInspector(
            $dispatcher,
            $stateFile,
            new FakeExec(['201']),
            0,
        );

        $this->assertTrue($inspector->stopServer());
        $this->assertSame([
            [100, SIGTERM],
            [200, SIGTERM],
            [201, SIGTERM],
            [100, SIGINT],
            [100, SIGKILL],
            [200, SIGKILL],
            [201, SIGKILL],
        ], $dispatcher->signals);
    }

    public function test_stop_server_delegates_to_starter_process_when_it_owns_master(): void
    {
        $stateFile = $this->stateFile(100, 200, ['starterProcessId' => 999]);
        $dispatcher = new DelegatingSignalDispatcher();
        $dispatcher->running = [100 => true, 200 => true, 201 => true, 999 => true];

        $inspector = new ServerProcessInspector(
            $dispatcher,
            $stateFile,
            new FakeExecByCommand([
                'ps -o ppid= -p 100' => ['999'],
                'pgrep -P 200' => ['201'],
            ]),
            1,
        );

        $this->assertTrue($inspector->stopServer());
        $this->assertSame([
            [999, SIGTERM],
        ], $dispatcher->signals);
    }

    private function stateFile(int $masterProcessId, int $managerProcessId, array $state = []): ServerStateFile
    {
        $path = tempnam(sys_get_temp_dir(), 'octane-state-');
        file_put_contents($path, json_encode([
            'masterProcessId' => $masterProcessId,
            'managerProcessId' => $managerProcessId,
            'state' => $state,
        ]));

        return new ServerStateFile($path);
    }
}

class RecordingSignalDispatcher extends SignalDispatcher
{
    public array $signals = [];

    public array $running = [];

    public ?int $stopAfterSignal = null;

    public function __construct()
    {
        parent::__construct(new SwooleExtension());
    }

    public function canCommunicateWith(int $processId): bool
    {
        return $this->running[$processId] ?? false;
    }

    public function signal(int $processId, int $signal): bool
    {
        if ($signal === 0) {
            return $this->canCommunicateWith($processId);
        }

        $this->signals[] = [$processId, $signal];

        if ($this->stopAfterSignal === $signal) {
            $this->running[$processId] = false;
        }

        return true;
    }
}

class FakeExec extends Exec
{
    public function __construct(private array $output)
    {
    }

    public function run($command)
    {
        return $this->output;
    }
}

class FakeExecByCommand extends Exec
{
    public function __construct(private array $outputByCommand)
    {
    }

    public function run($command)
    {
        return $this->outputByCommand[$command] ?? [];
    }
}

class DelegatingSignalDispatcher extends RecordingSignalDispatcher
{
    public function signal(int $processId, int $signal): bool
    {
        $result = parent::signal($processId, $signal);

        if ($processId === 999 && $signal === SIGTERM) {
            $this->running[100] = false;
            $this->running[200] = false;
            $this->running[201] = false;
        }

        return $result;
    }
}
