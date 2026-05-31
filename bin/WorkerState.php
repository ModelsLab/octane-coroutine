<?php

namespace Laravel\Octane\Swoole;

class WorkerState
{
    public $server;

    public $workerId;

    public $workerPid;

    public $worker;

    public $client;

    public $clientPool;

    public $workerPool;

    public $timerTable;

    public $cacheTable;

    public $tables = [];

    public $tickTimerId;

    public $lastRequestTime;

    public $ready = false;

    public int $handledRequests = 0;

    public int $maxRequests = 0;

    public bool $recycleRequested = false;

    public bool $recycleTriggered = false;
}
