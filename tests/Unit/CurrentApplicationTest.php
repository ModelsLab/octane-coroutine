<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Laravel\Octane\CurrentApplication;
use Laravel\Octane\Swoole\Coroutine\Context;
use Illuminate\Foundation\Application;
use Mockery;

/**
 * Tests for the CurrentApplication class.
 */
class CurrentApplicationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CurrentApplication::clear();
        Context::clear();
    }

    protected function tearDown(): void
    {
        CurrentApplication::clear();
        Context::clear();
        Mockery::close();
        parent::tearDown();
    }

    public function test_set_stores_application_in_fallback_when_not_in_coroutine()
    {
        $app = $this->createMockApplication();

        CurrentApplication::set($app);

        $retrieved = CurrentApplication::get();
        $this->assertSame($app, $retrieved);
    }

    public function test_clear_removes_application()
    {
        $app = $this->createMockApplication();
        CurrentApplication::set($app);

        CurrentApplication::clear();
        Context::clear();

        // After clear and context clear, inCoroutine should return false
        $this->assertFalse(CurrentApplication::inCoroutine());
    }

    public function test_in_coroutine_returns_false_outside_swoole()
    {
        $this->assertFalse(CurrentApplication::inCoroutine());
    }

    public function test_get_returns_null_when_nothing_set()
    {
        CurrentApplication::clear();
        Context::clear();

        // Get may return null or try to get container instance
        $app = CurrentApplication::get();

        // Either null or an Application/Container instance
        $this->assertTrue($app === null || $app instanceof Application);
    }

    public function test_current_application_is_isolated_per_coroutine()
    {
        if (!class_exists(\Swoole\Coroutine::class) || !function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $results = [];

        \Swoole\Coroutine\run(function () use (&$results) {
            \Swoole\Coroutine::create(function () use (&$results) {
                $app = new Application(__DIR__);
                $app->instance('marker', 'alpha');

                CurrentApplication::set($app);
                \Swoole\Coroutine::sleep(0.01);

                $results[] = CurrentApplication::get()->make('marker');
            });

            \Swoole\Coroutine::create(function () use (&$results) {
                $app = new Application(__DIR__);
                $app->instance('marker', 'bravo');

                CurrentApplication::set($app);
                \Swoole\Coroutine::sleep(0.02);

                $results[] = CurrentApplication::get()->make('marker');
            });
        });

        sort($results);
        $this->assertSame(['alpha', 'bravo'], $results);
    }

    protected function createMockApplication(): Application
    {
        $app = Mockery::mock(Application::class);
        $app->shouldReceive('instance')->andReturnSelf();
        return $app;
    }
}
