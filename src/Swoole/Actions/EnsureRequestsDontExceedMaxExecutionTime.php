<?php

namespace Laravel\Octane\Swoole\Actions;

use Laravel\Octane\Swoole\SwooleExtension;
use Swoole\Coroutine;
use Swoole\Http\Response;
use Swoole\Http\Server;

/**
 * Ensures requests don't exceed the maximum execution time.
 *
 * In coroutine mode, we track coroutine IDs instead of worker IDs.
 * When a coroutine exceeds the max time, we cancel only that coroutine
 * instead of killing the entire worker (which would drop all concurrent requests).
 */
class EnsureRequestsDontExceedMaxExecutionTime
{
    public function __construct(
        protected SwooleExtension $extension,
        protected $timerTable,
        protected $maxExecutionTime,
        protected ?Server $server = null,
        protected int|string|null $fallbackSignal = SIGTERM,
    ) {
        $this->fallbackSignal = self::normalizeFallbackSignal($this->fallbackSignal);
    }

    /**
     * Invoke the action.
     *
     * @return void
     */
    public function __invoke()
    {
        $rows = [];

        foreach ($this->timerTable as $coroutineId => $row) {
            if ((time() - $row['time']) > $this->maxExecutionTime) {
                $rows[$coroutineId] = $row;
            }
        }

        foreach ($rows as $coroutineId => $row) {
            // Double-check that this entry is still for the same request
            if ($this->timerTable->get($coroutineId, 'fd') !== $row['fd']) {
                continue;
            }

            // Delete the timer entry first
            $this->timerTable->del($coroutineId);

            $age = time() - $row['time'];

            // Check if the connection still exists. Log this case too: a
            // dead fd here means a downstream proxy already gave up on a
            // request the worker is STILL burning - silence made a whole
            // class of production stalls invisible (the 210s nginx 504s).
            if ($this->server instanceof Server && ! $this->server->exists($row['fd'])) {
                error_log("⏱️ Request timeout (client gone): Coroutine #{$coroutineId} ran {$age}s of {$this->maxExecutionTime}s budget on worker {$row['worker_pid']}; fd {$row['fd']} already closed");

                $this->cancelCoroutine($coroutineId);

                continue;
            }

            // Log the timeout
            error_log("⏱️ Request timeout: Coroutine #{$coroutineId} ran {$age}s, exceeded {$this->maxExecutionTime}s max execution time (worker {$row['worker_pid']}, fd {$row['fd']})");

            $cancelled = $this->cancelCoroutine($coroutineId);

            if (!$cancelled) {
                // If coroutine cancellation failed, ask Swoole to recycle the
                // worker. SIGTERM is the default so active request cleanup has
                // a chance to run; SIGKILL remains configurable as a last resort.
                error_log("⚠️ Failed to cancel coroutine #{$coroutineId}, falling back to worker {$this->fallbackSignalName()} (PID: {$row['worker_pid']})");
                $this->extension->dispatchProcessSignal($row['worker_pid'], $this->fallbackSignal);
            }

            // Try to send a 408 timeout response
            if ($this->server instanceof Server) {
                try {
                    $response = Response::create($this->server, $row['fd']);

                    if ($response) {
                        $response->status(408);
                        $response->header('Content-Type', 'application/json');
                        $response->end(json_encode([
                            'error' => 'Request Timeout',
                            'message' => "Request exceeded maximum execution time of {$this->maxExecutionTime} seconds",
                        ]));
                    }
                } catch (\Throwable $e) {
                    // Response may already be closed
                    error_log("⚠️ Could not send 408 response: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Attempt to cancel a specific coroutine.
     *
     * @param  int  $coroutineId
     * @return bool
     */
    protected function cancelCoroutine(int $coroutineId): bool
    {
        try {
            // Check if coroutine exists
            if (method_exists(Coroutine::class, 'exists') && !Coroutine::exists($coroutineId)) {
                error_log("ℹ️ Coroutine #{$coroutineId} no longer exists (already completed)");
                return true; // Consider it handled
            }

            if (!method_exists(Coroutine::class, 'cancel')) {
                error_log("⚠️ Coroutine::cancel not available; falling back to worker kill");
                return false;
            }

            // Cancel the coroutine
            // This throws a Swoole\Coroutine\Cancelation exception in the coroutine
            $result = Coroutine::cancel($coroutineId);

            if ($result) {
                error_log("✅ Successfully cancelled coroutine #{$coroutineId}");
            } else {
                error_log("❌ Coroutine::cancel() returned false for #{$coroutineId}");
            }

            return $result;
        } catch (\Throwable $e) {
            error_log("❌ Error cancelling coroutine #{$coroutineId}: " . $e->getMessage());
            return false;
        }
    }

    public static function normalizeFallbackSignal(int|string|null $signal): int
    {
        if (is_int($signal)) {
            return in_array($signal, [SIGTERM, SIGKILL], true) ? $signal : SIGTERM;
        }

        $signal = strtoupper(trim((string) $signal));

        return match ($signal) {
            'KILL', 'SIGKILL', (string) SIGKILL => SIGKILL,
            default => SIGTERM,
        };
    }

    protected function fallbackSignalName(): string
    {
        return $this->fallbackSignal === SIGKILL ? 'SIGKILL' : 'SIGTERM';
    }
}
