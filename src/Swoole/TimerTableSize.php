<?php

namespace Laravel\Octane\Swoole;

class TimerTableSize
{
    public static function fromServerState(array $serverState): int
    {
        $octaneConfig = $serverState['octaneConfig'] ?? [];
        $configuredSize = self::positiveInt($octaneConfig['max_timer_table_size'] ?? null);

        if ($configuredSize !== null) {
            return max(1000, $configuredSize);
        }

        $options = array_merge(
            $serverState['defaultServerOptions'] ?? [],
            $octaneConfig['swoole']['options'] ?? [],
        );

        $workerNum = max(1, self::positiveInt($options['worker_num'] ?? null) ?? 1);
        $poolSize = max(1, self::positiveInt($octaneConfig['swoole']['pool']['size'] ?? null) ?? 10);
        $legacyPoolEstimate = $poolSize * $workerNum * 2;

        return max(
            1000,
            $legacyPoolEstimate,
            self::positiveInt($options['max_conn'] ?? null) ?? 0,
            self::positiveInt($options['max_coroutine'] ?? null) ?? 0,
        );
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;

            return $value > 0 ? $value : null;
        }

        return null;
    }
}
