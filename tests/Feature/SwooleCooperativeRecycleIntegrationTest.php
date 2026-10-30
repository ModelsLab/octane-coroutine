<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class SwooleCooperativeRecycleIntegrationTest extends TestCase
{
    private array $servers = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is required for the integration recycle probe.');
        }

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is required for the integration recycle probe.');
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->servers) as $server) {
            $this->stopServer($server);
        }

        parent::tearDown();
    }

    public function test_serial_requests_keep_returning_200_across_cooperative_worker_recycle(): void
    {
        $server = $this->startServer(maxRequests: 3);
        $responses = [];

        for ($i = 1; $i <= 6; $i++) {
            $responses[] = $this->getJson($server['port'], "/serial-{$i}");
        }

        $this->assertCount(6, $responses);
        $this->assertSame(['ok'], array_values(array_unique(array_column($responses, 'status'))));

        $pids = array_values(array_unique(array_column($responses, 'pid')));

        $this->assertGreaterThanOrEqual(2, count($pids), 'Expected worker PID to change after cooperative recycle.');
        $this->assertStringContainsString('recycled=1', file_get_contents($server['eventLog']));
        $this->assertGreaterThanOrEqual(2, substr_count(file_get_contents($server['eventLog']), 'worker-start:'));
    }

    public function test_overlapping_requests_finish_before_worker_recycles(): void
    {
        if (! function_exists('curl_multi_init')) {
            $this->markTestSkipped('curl extension is required for concurrent request probing.');
        }

        $server = $this->startServer(maxRequests: 1);
        $responses = $this->getJsonConcurrently($server['port'], [
            '/slow-a?sleep_ms=250',
            '/slow-b?sleep_ms=500',
        ]);

        $this->assertCount(2, $responses);
        $this->assertSame(['ok'], array_values(array_unique(array_column($responses, 'status'))));
        $this->assertSame(2, count($responses), 'Both overlapping requests must receive complete JSON responses.');

        $this->waitForLogCount($server, 'worker-start:', 2);

        $eventLog = file_get_contents($server['eventLog']);

        $this->assertStringContainsString('active=1:requested=1:triggered=0:recycled=0', $eventLog);
        $this->assertStringContainsString('active=0:requested=1:triggered=1:recycled=1', $eventLog);
        $this->assertGreaterThanOrEqual(2, substr_count($eventLog, 'worker-start:'));
    }

    public function test_batched_concurrent_requests_survive_multiple_worker_recycles(): void
    {
        if (! function_exists('curl_multi_init')) {
            $this->markTestSkipped('curl extension is required for concurrent request probing.');
        }

        $server = $this->startServer(maxRequests: 3);
        $responses = [];

        for ($batch = 1; $batch <= 4; $batch++) {
            $paths = [];

            for ($request = 1; $request <= 8; $request++) {
                $paths[] = "/batch-{$batch}-{$request}?sleep_ms=50";
            }

            array_push($responses, ...$this->getJsonConcurrently($server['port'], $paths));
            $this->getJson($server['port'], "/batch-{$batch}-health");
        }

        $this->assertCount(32, $responses);
        $this->assertSame(['ok'], array_values(array_unique(array_column($responses, 'status'))));

        $this->waitForLogCount($server, 'recycled=1', 4);

        $eventLog = file_get_contents($server['eventLog']);

        $this->assertGreaterThanOrEqual(4, substr_count($eventLog, 'recycled=1'));
        $this->assertStringNotContainsString('active=1:requested=1:triggered=1', $eventLog);
    }

    private function startServer(int $maxRequests): array
    {
        $port = $this->findFreePort();
        $prefix = tempnam(sys_get_temp_dir(), 'octane-recycle-');

        if ($prefix === false) {
            $this->fail('Unable to allocate temporary files for Swoole recycle probe.');
        }

        @unlink($prefix);

        $readyFile = $prefix.'.ready';
        $eventLog = $prefix.'.events.log';
        $stdout = $prefix.'.stdout.log';
        $stderr = $prefix.'.stderr.log';
        $fixture = dirname(__DIR__).'/Fixtures/swoole_cooperative_recycle_server.php';
        $command = sprintf(
            '%s %s %d %d %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($fixture),
            $port,
            $maxRequests,
            escapeshellarg($readyFile),
            escapeshellarg($eventLog),
        );

        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['file', $stdout, 'a'],
            2 => ['file', $stderr, 'a'],
        ], $pipes);

        if (! is_resource($process)) {
            $this->fail('Unable to start Swoole recycle probe process.');
        }

        fclose($pipes[0]);

        $server = compact('process', 'port', 'readyFile', 'eventLog', 'stdout', 'stderr');
        $this->servers[] = $server;

        $this->waitForServer($server);

        return $server;
    }

    private function stopServer(array $server): void
    {
        if (is_resource($server['process'])) {
            $this->getRaw($server['port'], '/shutdown', attempts: 1);

            $deadline = microtime(true) + 2.0;

            do {
                $status = proc_get_status($server['process']);

                if (! $status['running']) {
                    proc_close($server['process']);
                    $this->cleanupServerFiles($server);

                    return;
                }

                usleep(50000);
            } while (microtime(true) < $deadline);

            proc_terminate($server['process']);
            usleep(100000);

            $status = proc_get_status($server['process']);

            if ($status['running']) {
                proc_terminate($server['process'], 9);
            }

            proc_close($server['process']);
        }

        $this->cleanupServerFiles($server);
    }

    private function cleanupServerFiles(array $server): void
    {
        foreach (['readyFile', 'eventLog', 'stdout', 'stderr'] as $key) {
            if (isset($server[$key]) && is_string($server[$key]) && is_file($server[$key])) {
                @unlink($server[$key]);
            }
        }
    }

    private function waitForServer(array $server): void
    {
        $deadline = microtime(true) + 5.0;

        do {
            $status = proc_get_status($server['process']);

            if (! $status['running']) {
                $stderr = is_file($server['stderr']) ? file_get_contents($server['stderr']) : '';
                $this->fail('Swoole recycle probe exited before becoming ready: '.$stderr);
            }

            if (
                is_file($server['readyFile'])
                && is_file($server['eventLog'])
                && str_contains(file_get_contents($server['eventLog']), 'worker-start:')
            ) {
                return;
            }

            usleep(50000);
        } while (microtime(true) < $deadline);

        $stderr = is_file($server['stderr']) ? file_get_contents($server['stderr']) : '';
        $this->fail('Timed out waiting for Swoole recycle probe to start: '.$stderr);
    }

    private function waitForLogCount(array $server, string $needle, int $minimumCount): void
    {
        $deadline = microtime(true) + 5.0;

        do {
            $log = is_file($server['eventLog']) ? file_get_contents($server['eventLog']) : '';

            if (substr_count($log, $needle) >= $minimumCount) {
                return;
            }

            usleep(50000);
        } while (microtime(true) < $deadline);

        $log = is_file($server['eventLog']) ? file_get_contents($server['eventLog']) : '';

        $this->fail("Timed out waiting for {$minimumCount} occurrences of {$needle}. Current log:\n{$log}");
    }

    private function getJson(int $port, string $path, int $attempts = 20): array
    {
        $raw = $this->getRaw($port, $path, $attempts);

        $this->assertNotNull($raw, "Request {$path} did not return a response.");

        $decoded = json_decode($raw, true);

        $this->assertIsArray($decoded, "Request {$path} returned non-JSON body: {$raw}");

        return $decoded;
    }

    private function getRaw(int $port, string $path, int $attempts = 20): ?string
    {
        $url = "http://127.0.0.1:{$port}{$path}";

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $context = stream_context_create([
                'http' => [
                    'ignore_errors' => true,
                    'timeout' => 2,
                ],
            ]);
            $raw = @file_get_contents($url, false, $context);

            if (is_string($raw) && $raw !== '') {
                return $raw;
            }

            usleep(50000);
        }

        return null;
    }

    private function getJsonConcurrently(int $port, array $paths): array
    {
        $multi = curl_multi_init();
        $handles = [];

        foreach ($paths as $path) {
            $handle = curl_init("http://127.0.0.1:{$port}{$path}");

            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 500,
                CURLOPT_TIMEOUT_MS => 3000,
            ]);

            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }

        do {
            $status = curl_multi_exec($multi, $running);

            if ($running > 0) {
                curl_multi_select($multi, 0.05);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $responses = [];

        foreach ($handles as $handle) {
            $body = curl_multi_getcontent($handle);
            $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $error = curl_error($handle);

            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);

            $this->assertSame(200, $httpCode, "Concurrent request failed with HTTP {$httpCode}: {$error}");

            $decoded = json_decode($body, true);

            $this->assertIsArray($decoded, "Concurrent request returned non-JSON body: {$body}");

            $responses[] = $decoded;
        }

        curl_multi_close($multi);

        return $responses;
    }

    private function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if (! is_resource($socket)) {
            $this->fail("Unable to allocate free port: {$errstr} ({$errno})");
        }

        $name = stream_socket_get_name($socket, false);

        fclose($socket);

        return (int) substr(strrchr($name, ':'), 1);
    }
}
