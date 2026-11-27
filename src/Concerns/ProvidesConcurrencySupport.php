<?php

namespace Laravel\Octane\Concerns;

use Laravel\Octane\Contracts\DispatchesTasks;
use Laravel\Octane\SequentialTaskDispatcher;
use Laravel\Octane\Swoole\SwooleTaskDispatcher;
use Swoole\Http\Server;

trait ProvidesConcurrencySupport
{
    /**
     * Concurrently resolve the given callbacks via background tasks, returning the results.
     *
     * Results will be keyed by their given keys - if a task did not finish, the tasks value will be "false".
     *
     * @return array
     *
     * @throws \Laravel\Octane\Exceptions\TaskException
     * @throws \Laravel\Octane\Exceptions\TaskTimeoutException
     */
    public function concurrently(array $tasks, int $waitMilliseconds = 3000)
    {
        if (empty($tasks)) {
            return [];
        }

        return $this->tasks()->resolve($tasks, $waitMilliseconds);
    }

    /**
     * Get the task dispatcher.
     *
     * @return \Laravel\Octane\Contracts\DispatchesTasks
     */
    public function tasks()
    {
        return match (true) {
            app()->bound(DispatchesTasks::class) => app(DispatchesTasks::class),
            app()->bound(Server::class) => new SwooleTaskDispatcher,
            default => new SequentialTaskDispatcher,
        };
    }
}
