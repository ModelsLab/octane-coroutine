<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Laravel\Octane\Swoole\Coroutine\Monitor;

/**
 * Tests for the coroutine Monitor class.
 */
class CoroutineMonitorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monitor::clearRequestCoroutines();
    }

    protected function tearDown(): void
    {
        Monitor::clearRequestCoroutines();
        parent::tearDown();
    }

    public function test_register_request_coroutine_tracks_coroutine()
    {
        $this->assertEquals(0, Monitor::getActiveRequestCount());

        Monitor::registerRequestCoroutine(1);

        $this->assertEquals(1, Monitor::getActiveRequestCount());
        $this->assertContains(1, Monitor::getActiveRequestCoroutines());
    }

    public function test_unregister_request_coroutine_removes_tracking()
    {
        Monitor::registerRequestCoroutine(1);
        Monitor::registerRequestCoroutine(2);

        $this->assertEquals(2, Monitor::getActiveRequestCount());

        Monitor::unregisterRequestCoroutine(1);

        $this->assertEquals(1, Monitor::getActiveRequestCount());
        $this->assertNotContains(1, Monitor::getActiveRequestCoroutines());
        $this->assertContains(2, Monitor::getActiveRequestCoroutines());
    }

    public function test_multiple_coroutines_tracked_independently()
    {
        Monitor::registerRequestCoroutine(10);
        Monitor::registerRequestCoroutine(20);
        Monitor::registerRequestCoroutine(30);

        $this->assertEquals(3, Monitor::getActiveRequestCount());

        $activeIds = Monitor::getActiveRequestCoroutines();
        $this->assertContains(10, $activeIds);
        $this->assertContains(20, $activeIds);
        $this->assertContains(30, $activeIds);
    }

    public function test_clear_removes_all_tracked_coroutines()
    {
        Monitor::registerRequestCoroutine(1);
        Monitor::registerRequestCoroutine(2);
        Monitor::registerRequestCoroutine(3);

        Monitor::clearRequestCoroutines();

        $this->assertEquals(0, Monitor::getActiveRequestCount());
        $this->assertEmpty(Monitor::getActiveRequestCoroutines());
    }

    public function test_stats_returns_array_with_required_keys()
    {
        $stats = Monitor::stats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('enabled', $stats);
        $this->assertArrayHasKey('active_requests', $stats);
    }

    public function test_report_returns_string()
    {
        $report = Monitor::getReport();

        $this->assertIsString($report);
        $this->assertStringContainsString('Coroutine', $report);
    }

    public function test_unregister_nonexistent_coroutine_does_not_throw()
    {
        // Should not throw when unregistering a non-existent coroutine
        Monitor::unregisterRequestCoroutine(999);

        $this->assertEquals(0, Monitor::getActiveRequestCount());
    }

    public function test_get_active_request_coroutines_returns_correct_ids()
    {
        Monitor::registerRequestCoroutine(100);
        Monitor::registerRequestCoroutine(200);

        $ids = Monitor::getActiveRequestCoroutines();

        $this->assertCount(2, $ids);
        $this->assertEquals([100, 200], $ids);
    }
}
