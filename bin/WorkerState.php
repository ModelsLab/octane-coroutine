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
}
