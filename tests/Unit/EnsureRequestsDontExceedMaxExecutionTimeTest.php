<?php

namespace Tests\Unit;

use ArrayIterator;
use IteratorAggregate;
use PHPUnit\Framework\TestCase;
use Traversable;
use Laravel\Octane\Swoole\Actions\EnsureRequestsDontExceedMaxExecutionTime;
use Laravel\Octane\Swoole\SwooleExtension;

class EnsureRequestsDontExceedMaxExecutionTimeTest extends TestCase
{
    public function test_timeout_cancels_coroutine_without_killing_worker(): void
    {
        $table = new FakeTimerTable([
            123 => [
                'worker_pid' => 111,
                'time' => time() - 10,
                'fd' => 5,
            ],
        ]);

        $extension = new RecordingSwooleExtension();
        $action = new TestableEnsureRequestsDontExceedMaxExecutionTime(
            $extension,
            $table,
            5
        );

        $action->__invoke();

        $this->assertSame([123], $action->cancelledIds);
        $this->assertSame([], $extension->signals);
        $this->assertSame([123], $table->deletedIds);
    }

    public function test_timeout_falls_back_to_worker_sigterm_when_cancel_fails(): void
    {
        $table = new FakeTimerTable([
            55 => [
                'worker_pid' => 222,
                'time' => time() - 20,
                'fd' => 9,
            ],
        ]);

        $extension = new RecordingSwooleExtension();
        $action = new TestableEnsureRequestsDontExceedMaxExecutionTime(
            $extension,
            $table,
            5
        );
        $action->cancelResult = false;

        $action->__invoke();

        $this->assertSame([55], $action->cancelledIds);
        $this->assertSame([[222, SIGTERM]], $extension->signals);
        $this->assertSame([55], $table->deletedIds);
    }

    public function test_timeout_fallback_can_still_be_configured_to_sigkill(): void
    {
        $table = new FakeTimerTable([
            56 => [
                'worker_pid' => 223,
                'time' => time() - 20,
                'fd' => 10,
            ],
        ]);

        $extension = new RecordingSwooleExtension();
        $action = new TestableEnsureRequestsDontExceedMaxExecutionTime(
            $extension,
            $table,
            5,
            null,
            'SIGKILL',
        );
        $action->cancelResult = false;

        $action->__invoke();

        $this->assertSame([[223, SIGKILL]], $extension->signals);
    }

    public function test_non_expired_requests_are_ignored(): void
    {
        $table = new FakeTimerTable([
            7 => [
                'worker_pid' => 333,
                'time' => time(),
                'fd' => 3,
            ],
        ]);

        $extension = new RecordingSwooleExtension();
        $action = new TestableEnsureRequestsDontExceedMaxExecutionTime(
            $extension,
            $table,
            30
        );

        $action->__invoke();

        $this->assertSame([], $action->cancelledIds);
        $this->assertSame([], $extension->signals);
        $this->assertSame([], $table->deletedIds);
    }
}

class FakeTimerTable implements IteratorAggregate
{
    public array $deletedIds = [];

    /**
     * @var array<int, array<string, int>>
     */
    private array $rows;

    /**
     * @param array<int, array<string, int>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rows);
    }

    public function get(int $key, string $column)
    {
        return $this->rows[$key][$column] ?? null;
    }

    public function del(int $key): void
    {
        $this->deletedIds[] = $key;
        unset($this->rows[$key]);
    }
}

class RecordingSwooleExtension extends SwooleExtension
{
    /**
     * @var array<int, array<int, int>>
     */
    public array $signals = [];

    public function dispatchProcessSignal(int $pid, int $signal): bool
    {
        $this->signals[] = [$pid, $signal];
        return true;
    }
}

class TestableEnsureRequestsDontExceedMaxExecutionTime extends EnsureRequestsDontExceedMaxExecutionTime
{
    public bool $cancelResult = true;
    public array $cancelledIds = [];

    protected function cancelCoroutine(int $coroutineId): bool
    {
        $this->cancelledIds[] = $coroutineId;
        return $this->cancelResult;
    }
}
