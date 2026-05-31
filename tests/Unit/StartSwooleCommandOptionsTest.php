<?php

namespace Tests\Unit;

use Laravel\Octane\Commands\StartSwooleCommand;
use Laravel\Octane\Swoole\SwooleExtension;
use Tests\TestCase;

class StartSwooleCommandOptionsTest extends TestCase
{
    public function test_swoole_options_include_configured_max_request_grace(): void
    {
        config(['octane.swoole.max_request_grace' => 250]);

        $command = new TestableStartSwooleCommandForOptions([
            'max-requests' => 1000,
            'max-request-grace' => null,
        ]);

        $options = $command->defaultServerOptionsForTest(new SwooleExtension());

        $this->assertSame(0, $options['max_request']);
        $this->assertSame(0, $options['max_request_grace']);
        $this->assertSame(1000, $options['task_max_request']);
        $this->assertSame(250, $options['task_max_request_grace']);
    }

    public function test_swoole_task_options_default_to_ten_percent_max_request_grace(): void
    {
        config(['octane.swoole.max_request_grace' => null]);

        $command = new TestableStartSwooleCommandForOptions([
            'max-requests' => 100,
            'max-request-grace' => null,
        ]);

        $options = $command->defaultServerOptionsForTest(new SwooleExtension());

        $this->assertSame(0, $options['max_request']);
        $this->assertSame(0, $options['max_request_grace']);
        $this->assertSame(100, $options['task_max_request']);
        $this->assertSame(10, $options['task_max_request_grace']);
    }

    public function test_custom_swoole_options_cannot_reenable_builtin_http_max_request_recycling(): void
    {
        config([
            'octane.swoole.options' => [
                'max_request' => 500,
                'max_request_grace' => 1000,
                'task_max_request' => 250,
                'task_max_request_grace' => 25,
            ],
        ]);

        $command = new TestableStartSwooleCommandForOptions([
            'max-requests' => 100,
            'max-request-grace' => null,
        ]);

        $options = $command->defaultServerOptionsForTest(new SwooleExtension());

        $this->assertSame(0, $options['max_request']);
        $this->assertSame(0, $options['max_request_grace']);
        $this->assertSame(250, $options['task_max_request']);
        $this->assertSame(25, $options['task_max_request_grace']);
    }
}

class TestableStartSwooleCommandForOptions extends StartSwooleCommand
{
    public function __construct(private array $testOptions)
    {
        parent::__construct();
    }

    public function option($key = null)
    {
        return $this->testOptions[$key] ?? null;
    }

    public function defaultServerOptionsForTest(SwooleExtension $extension): array
    {
        return $this->defaultServerOptions($extension);
    }

    protected function workerCount(SwooleExtension $extension)
    {
        return 4;
    }

    protected function taskWorkerCount(SwooleExtension $extension)
    {
        return 2;
    }
}
