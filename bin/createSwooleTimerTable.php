<?php

use Laravel\Octane\Tables\TableFactory;
use Swoole\Table;

require_once __DIR__.'/../src/Tables/TableFactory.php';

if (($serverState['octaneConfig']['max_execution_time'] ?? 0) > 0) {
    $poolSize = $serverState['octaneConfig']['swoole']['pool']['size'] ?? 10;
    $workerNum = $serverState['octaneConfig']['swoole']['options']['worker_num'] ?? 4;

    // Calculate recommended size: pool_size * worker_num * 2 (for safety margin)
    $recommendedSize = $poolSize * $workerNum * 2;

    // Use configured size or fall back to recommended, with minimum of 1000
    $configuredSize = $serverState['octaneConfig']['max_timer_table_size'] ?? null;
    $timerTableSize = $configuredSize ?? max(1000, $recommendedSize);

    // Ensure minimum reasonable size
    $timerTableSize = max($timerTableSize, 1000);

    error_log("📊 Creating timer table with size: {$timerTableSize} (pool: {$poolSize}, workers: {$workerNum})");

    $timerTable = TableFactory::make($timerTableSize);

    $timerTable->column('worker_pid', Table::TYPE_INT);
    $timerTable->column('time', Table::TYPE_INT);
    $timerTable->column('fd', Table::TYPE_INT);

    $timerTable->create();

    return $timerTable;
}

return null;
