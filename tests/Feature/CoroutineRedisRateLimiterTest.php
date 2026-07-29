<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end cover for the Swoole worker kill described in
 * "Socket#N has already been bound to another coroutine#M".
 *
 * Illuminate registers RateLimiter as a boot-time singleton wrapping a cache
 * Repository, so its RedisStore keeps pointing at the worker-level Redis
 * manager. Under Swoole every concurrent request then throttles through one
 * hooked phpredis socket and the worker dies.
 *
 * The probe runs in a subprocess because the error is raised by the Swoole
 * scheduler rather than thrown through the calling frame: it cannot be caught,
 * and it takes the whole process with it. Surviving the probe is therefore
 * observable only as "the child exited cleanly".
 */
class CoroutineRedisRateLimiterTest extends TestCase
{
    private const PROBE = __DIR__.'/../Fixtures/coroutine_redis_ratelimiter_probe.php';

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is required for the coroutine Redis probe.');
        }

        if (! extension_loaded('redis')) {
            $this->markTestSkipped('phpredis is required for the coroutine Redis probe.');
        }

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is required for the coroutine Redis probe.');
        }

        if (! $this->redisIsReachable()) {
            $this->markTestSkipped('A Redis server on 127.0.0.1:6379 is required for the coroutine Redis probe.');
        }
    }

    /**
     * Guards the probe itself: if this stops failing, the harness has stopped
     * reproducing the bug and the passing cases below prove nothing.
     */
    public function test_boot_time_rate_limiter_on_a_stock_manager_kills_the_process(): void
    {
        $probe = $this->runProbe(manager: 'stock', resolve: 'base');

        $this->assertFalse($probe['completed'], 'Expected the shared phpredis socket to kill the probe process.');
        $this->assertNotSame(0, $probe['exitCode']);
        $this->assertMatchesRegularExpression(
            '/Socket#\d+ has already been bound to another coroutine#\d+/',
            $probe['output']
        );

        // Same call path as the production stack trace.
        $this->assertStringContainsString('Illuminate\Cache\RateLimiter->tooManyAttempts', $probe['output']);
        $this->assertStringContainsString('Illuminate\Cache\RedisStore->get', $probe['output']);
    }

    /**
     * Defence one: RequestScope hands the coroutine its own rate limiter, built
     * on the coroutine-local cache repository.
     */
    public function test_request_scoped_rate_limiter_survives_concurrent_coroutines(): void
    {
        $this->assertProbeSurvives(manager: 'stock', resolve: 'scope');
    }

    /**
     * Defence two: the worker-level manager itself is coroutine-aware, so even a
     * singleton captured at boot -- one this package never sees -- gets a
     * per-coroutine connection.
     */
    public function test_coroutine_aware_manager_protects_unscoped_boot_time_singletons(): void
    {
        $this->assertProbeSurvives(manager: 'coroutine', resolve: 'base');
    }

    /**
     * Both defences together, which is what the service provider installs.
     */
    public function test_production_configuration_survives_concurrent_coroutines(): void
    {
        $this->assertProbeSurvives(manager: 'coroutine', resolve: 'scope');
    }

    private function assertProbeSurvives(string $manager, string $resolve): void
    {
        $probe = $this->runProbe($manager, $resolve);

        $this->assertTrue(
            $probe['completed'],
            "Probe did not finish (manager={$manager}, resolve={$resolve}): ".$probe['output']
        );
        $this->assertSame(0, $probe['exitCode'], $probe['output']);
        $this->assertStringNotContainsString('has already been bound to another coroutine', $probe['output']);

        $result = $probe['result'];

        $this->assertSame([], $result['errors'], 'Coroutines reported Redis errors: '.json_encode($result['errors']));
        $this->assertFalse($result['shared_socket'], 'Concurrent coroutines were handed the same phpredis socket.');
        $this->assertNotSame($result['sockets']['a'], $result['sockets']['b']);
    }

    /**
     * @return array{completed: bool, exitCode: int, output: string, result: array|null}
     */
    private function runProbe(string $manager, string $resolve): array
    {
        $command = sprintf(
            '%s %s --manager=%s --resolve=%s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::PROBE),
            escapeshellarg($manager),
            escapeshellarg($resolve)
        );

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            $this->fail('Unable to start the coroutine Redis probe.');
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $completed = str_contains($output, 'PROBE-COMPLETED');
        $result = null;

        if ($completed) {
            foreach (explode("\n", $output) as $line) {
                $decoded = json_decode(trim($line), true);

                if (is_array($decoded) && isset($decoded['sockets'])) {
                    $result = $decoded;
                    break;
                }
            }
        }

        return [
            'completed' => $completed,
            'exitCode' => $exitCode,
            'output' => $output,
            'result' => $result,
        ];
    }

    private function redisIsReachable(): bool
    {
        $socket = @fsockopen('127.0.0.1', 6379, $errno, $errstr, 0.5);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
