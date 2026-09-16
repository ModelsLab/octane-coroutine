<?php

use Laravel\Octane\Swoole\Actions\StopWorkerIfMaxRequestsExceeded;
use Laravel\Octane\Swoole\Coroutine\Monitor;
use Laravel\Octane\Swoole\WorkerState;
use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Swoole\Timer;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require_once dirname(__DIR__, 2).'/bin/WorkerState.php';

$port = (int) ($argv[1] ?? 0);
$maxRequests = (int) ($argv[2] ?? 1);
$readyFile = $argv[3] ?? null;
$eventLog = $argv[4] ?? null;

if ($port <= 0 || ! $readyFile || ! $eventLog) {
    fwrite(STDERR, "Usage: php swoole_cooperative_recycle_server.php <port> <max-requests> <ready-file> <event-log>\n");
    exit(2);
}

$server = new Server('127.0.0.1', $port, SWOOLE_PROCESS);
$workerState = new WorkerState();

$log = static function (string $message) use ($eventLog): void {
    file_put_contents($eventLog, $message.PHP_EOL, FILE_APPEND);
};

$server->set([
    'worker_num' => 1,
    'task_worker_num' => 0,
    'enable_coroutine' => true,
    'daemonize' => false,
    'log_file' => sys_get_temp_dir().'/octane-coroutine-recycle-swoole.log',
    'log_level' => SWOOLE_LOG_ERROR,
    'max_request' => 0,
    'max_request_grace' => 0,
    'reload_async' => true,
    'max_wait_time' => 5,
]);

$server->on('start', static function () use ($readyFile, $log): void {
    file_put_contents($readyFile, (string) getmypid());
    $log('server-start:'.getmypid());
});

$server->on('workerstart', static function (Server $server, int $workerId) use ($workerState, $maxRequests, $log): void {
    $workerState->server = $server;
    $workerState->workerId = $workerId;
    $workerState->workerPid = posix_getpid();
    $workerState->handledRequests = 0;
    $workerState->maxRequests = $maxRequests;
    $workerState->recycleRequested = false;
    $workerState->recycleTriggered = false;

    Monitor::clearRequestCoroutines();

    $log("worker-start:{$workerId}:".posix_getpid());
});

$server->on('workerstop', static function (Server $server, int $workerId) use ($log): void {
    $log("worker-stop:{$workerId}:".posix_getpid().':active='.Monitor::getActiveRequestCount());
});

$server->on('request', static function (Request $request, Response $response) use ($server, $workerState, $log): void {
    $cid = Coroutine::getCid();

    Monitor::registerRequestCoroutine($cid);

    try {
        $path = $request->server['request_uri'] ?? '/';

        if ($path === '/shutdown') {
            $response->status(200);
            $response->end('ok');
            Timer::after(50, static fn () => $server->shutdown());

            return;
        }

        $sleepMs = max(0, min(2000, (int) ($request->get['sleep_ms'] ?? 0)));

        if ($sleepMs > 0) {
            Coroutine::sleep($sleepMs / 1000);
        }

        $body = [
            'status' => 'ok',
            'path' => $path,
            'pid' => posix_getpid(),
            'worker_id' => $workerState->workerId,
            'handled_before' => $workerState->handledRequests,
            'active_before_end' => Monitor::getActiveRequestCount(),
        ];

        $response->status(200);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($body, JSON_THROW_ON_ERROR));
    } finally {
        Monitor::unregisterRequestCoroutine($cid);

        $didRecycle = (new StopWorkerIfMaxRequestsExceeded($server, $workerState))();

        $log(sprintf(
            'request-finish:pid=%d:handled=%d:max=%d:active=%d:requested=%d:triggered=%d:recycled=%d',
            posix_getpid(),
            $workerState->handledRequests,
            $workerState->maxRequests,
            Monitor::getActiveRequestCount(),
            $workerState->recycleRequested ? 1 : 0,
            $workerState->recycleTriggered ? 1 : 0,
            $didRecycle ? 1 : 0,
        ));
    }
});

$server->start();
