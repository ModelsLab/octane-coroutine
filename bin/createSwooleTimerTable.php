<?php

use Laravel\Octane\Swoole\TimerTableSize;
use Laravel\Octane\Tables\TableFactory;
use Swoole\Table;

require_once __DIR__.'/../src/Tables/TableFactory.php';
require_once __DIR__.'/../src/Swoole/TimerTableSize.php';

if (($serverState['octaneConfig']['max_execution_time'] ?? 0) > 0) {
    $timerTableSize = TimerTableSize::fromServerState($serverState);

    error_log("📊 Creating timer table with size: {$timerTableSize}");

    $timerTable = TableFactory::make($timerTableSize);

    $timerTable->column('worker_pid', Table::TYPE_INT);
    $timerTable->column('time', Table::TYPE_INT);
    $timerTable->column('fd', Table::TYPE_INT);

    $timerTable->create();

    return $timerTable;
}

return null;
