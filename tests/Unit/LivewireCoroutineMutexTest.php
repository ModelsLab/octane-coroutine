<?php

namespace Tests\Unit;

use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\LivewireCoroutineMutex;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

class LivewireCoroutineMutexTest extends TestCase
{
    public function test_it_serializes_livewire_sections_between_coroutines(): void
    {
        if (! extension_loaded('swoole') || ! class_exists(Channel::class)) {
            $this->markTestSkipped('Swoole is required for coroutine mutex tests.');
        }

        $events = [];

        \Swoole\Coroutine\run(function () use (&$events): void {
            $mutex = new LivewireCoroutineMutex;
            $firstAcquired = new Channel(1);

            Coroutine::create(function () use ($mutex, $firstAcquired, &$events): void {
                Context::clear();

                $mutex->acquire();
                $events[] = 'first acquired';
                $firstAcquired->push(true);

                Coroutine::sleep(0.05);

                $events[] = 'first releasing';
                $mutex->release();

                Context::clear();
            });

            $firstAcquired->pop();

            Coroutine::create(function () use ($mutex, &$events): void {
                Context::clear();

                $events[] = 'second waiting';
                $mutex->acquire();
                $events[] = 'second acquired';
                $mutex->release();

                Context::clear();
            });

            Coroutine::sleep(0.01);

            $events[] = 'checkpoint';

            Coroutine::sleep(0.08);
        });

        $this->assertSame([
            'first acquired',
            'second waiting',
            'checkpoint',
            'first releasing',
            'second acquired',
        ], $events);
    }

    public function test_it_is_reentrant_within_a_coroutine(): void
    {
        if (! extension_loaded('swoole') || ! class_exists(Channel::class)) {
            $this->markTestSkipped('Swoole is required for coroutine mutex tests.');
        }

        $completed = false;

        \Swoole\Coroutine\run(function () use (&$completed): void {
            $mutex = new LivewireCoroutineMutex;

            Context::clear();

            $mutex->acquire();
            $mutex->acquire();
            $mutex->release();
            $mutex->release();

            $completed = true;

            Context::clear();
        });

        $this->assertTrue($completed);
    }
}
