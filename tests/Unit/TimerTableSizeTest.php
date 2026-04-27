<?php

namespace Tests\Unit;

use Laravel\Octane\Swoole\TimerTableSize;
use PHPUnit\Framework\TestCase;

class TimerTableSizeTest extends TestCase
{
    public function test_configured_size_wins_with_minimum_floor(): void
    {
        $this->assertSame(1000, TimerTableSize::fromServerState([
            'octaneConfig' => [
                'max_timer_table_size' => 10,
                'swoole' => [
                    'options' => [
                        'max_conn' => 10000,
                    ],
                ],
            ],
        ]));

        $this->assertSame(50000, TimerTableSize::fromServerState([
            'octaneConfig' => [
                'max_timer_table_size' => '50000',
                'swoole' => [
                    'options' => [
                        'max_conn' => 10000,
                    ],
                ],
            ],
        ]));
    }

    public function test_pool_free_defaults_follow_swoole_connection_and_coroutine_limits(): void
    {
        $this->assertSame(50000, TimerTableSize::fromServerState([
            'defaultServerOptions' => [
                'worker_num' => 4,
                'max_conn' => 10000,
            ],
            'octaneConfig' => [
                'swoole' => [
                    'options' => [
                        'max_conn' => 25000,
                        'max_coroutine' => 50000,
                    ],
                ],
            ],
        ]));
    }

    public function test_legacy_pool_estimate_still_applies_when_it_is_larger(): void
    {
        $this->assertSame(2400, TimerTableSize::fromServerState([
            'defaultServerOptions' => [
                'worker_num' => 6,
                'max_conn' => 1000,
            ],
            'octaneConfig' => [
                'swoole' => [
                    'pool' => [
                        'size' => 200,
                    ],
                    'options' => [],
                ],
            ],
        ]));
    }
}
