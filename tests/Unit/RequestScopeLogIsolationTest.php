<?php

namespace Tests\Unit;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Log\LogManager;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RequestScopeLogIsolationTest extends TestCase
{
    public function test_log_manager_shared_context_is_isolated_per_request_scope(): void
    {
        $base = new Application(__DIR__);
        $base->instance('config', new ConfigRepository([
            'logging' => [
                'default' => 'stack',
                'channels' => [
                    'stack' => ['driver' => 'stack', 'channels' => ['single']],
                    'single' => ['driver' => 'single', 'path' => __DIR__.'/test.log'],
                ],
            ],
        ]));
        $base->instance('log', new LogManager($base));

        $sandbox = new CoroutineApplication($base);

        $firstScope = new RequestScope($base);
        Context::set('octane.request_scope', $firstScope);

        try {
            /** @var \Illuminate\Log\LogManager $firstLog */
            $firstLog = $sandbox->make('log');
            $firstLog->shareContext(['request_id' => 'req-1']);

            $this->assertSame(['request_id' => 'req-1'], $firstLog->sharedContext());
            $this->assertSame([], $base->make('log')->sharedContext());
            $this->assertSame($firstLog, $sandbox->make(LoggerInterface::class));
        } finally {
            Context::clear();
        }

        $secondScope = new RequestScope($base);
        Context::set('octane.request_scope', $secondScope);

        try {
            /** @var \Illuminate\Log\LogManager $secondLog */
            $secondLog = $sandbox->make('log');

            $this->assertSame([], $secondLog->sharedContext());

            $secondLog->shareContext(['request_id' => 'req-2']);

            $this->assertSame(['request_id' => 'req-2'], $secondLog->sharedContext());
            $this->assertSame([], $base->make('log')->sharedContext());
        } finally {
            Context::clear();
        }
    }
}
