<?php

namespace Tests\Unit;

use Laravel\Octane\Listeners\CreateConfigurationSandbox;
use Laravel\Octane\Listeners\GiveNewApplicationInstanceToHttpKernel;
use Laravel\Octane\Listeners\GiveNewApplicationInstanceToRouter;
use Laravel\Octane\Octane;
use PHPUnit\Framework\TestCase;

class CoroutineConfigurationTest extends TestCase
{
    private string|false $previousOctaneServer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousOctaneServer = getenv('OCTANE_SERVER');
    }

    protected function tearDown(): void
    {
        if ($this->previousOctaneServer === false) {
            putenv('OCTANE_SERVER');
        } else {
            putenv('OCTANE_SERVER='.$this->previousOctaneServer);
        }

        parent::tearDown();
    }

    public function test_swoole_coroutine_mode_moves_application_rebinding_listeners_to_worker_boot(): void
    {
        if (! extension_loaded('swoole')) {
            $this->markTestSkipped('Swoole extension is required.');
        }

        putenv('OCTANE_SERVER=swoole');

        $perRequestListeners = Octane::prepareApplicationForNextOperation();
        $bootListeners = Octane::prepareApplicationForCoroutineBoot();

        $this->assertNotContains(GiveNewApplicationInstanceToRouter::class, $perRequestListeners);
        $this->assertNotContains(GiveNewApplicationInstanceToHttpKernel::class, $perRequestListeners);
        $this->assertContains(CreateConfigurationSandbox::class, $perRequestListeners);

        $this->assertContains(GiveNewApplicationInstanceToRouter::class, $bootListeners);
        $this->assertContains(GiveNewApplicationInstanceToHttpKernel::class, $bootListeners);
        $this->assertNotContains(CreateConfigurationSandbox::class, $bootListeners);
    }

    public function test_non_swoole_modes_keep_application_rebinding_listeners_in_per_request_pipeline(): void
    {
        putenv('OCTANE_SERVER=roadrunner');

        $perRequestListeners = Octane::prepareApplicationForNextOperation();
        $bootListeners = Octane::prepareApplicationForCoroutineBoot();

        $this->assertContains(GiveNewApplicationInstanceToRouter::class, $perRequestListeners);
        $this->assertContains(GiveNewApplicationInstanceToHttpKernel::class, $perRequestListeners);
        $this->assertSame([], $bootListeners);
    }
}
