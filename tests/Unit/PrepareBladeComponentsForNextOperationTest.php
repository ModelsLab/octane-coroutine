<?php

namespace Tests\Unit;

use Illuminate\View\Component;
use Illuminate\View\FileViewFinder;
use Laravel\Octane\Listeners\PrepareBladeComponentsForNextOperation;
use Laravel\Octane\Octane;
use ReflectionClass;
use Tests\TestCase;

class PrepareBladeComponentsForNextOperationTest extends TestCase
{
    public function test_listener_is_registered_for_each_operation(): void
    {
        $this->assertContains(
            PrepareBladeComponentsForNextOperation::class,
            Octane::prepareApplicationForNextOperation(),
        );
    }

    public function test_registers_the_components_view_namespace_on_the_sandbox(): void
    {
        $listener = new PrepareBladeComponentsForNextOperation;

        $listener->handle((object) ['sandbox' => $this->app]);

        $finder = $this->app->make('view')->getFinder();
        $this->assertInstanceOf(FileViewFinder::class, $finder);

        $reflection = new ReflectionClass($finder);
        $hints = $reflection->getProperty('hints');
        $hints->setAccessible(true);

        $this->assertArrayHasKey('__components', $hints->getValue($finder));
        $this->assertSame(
            [config('view.compiled')],
            $hints->getValue($finder)['__components'],
        );
    }

    public function test_flushes_blade_component_static_cache_between_operations(): void
    {
        $reflection = new ReflectionClass(Component::class);
        $cache = $reflection->getProperty('bladeViewCache');
        $cache->setAccessible(true);
        $cache->setValue(null, ['test::cache' => '__components::cached']);

        $listener = new PrepareBladeComponentsForNextOperation;
        $listener->handle((object) ['sandbox' => $this->app]);

        $this->assertSame([], $cache->getValue());
    }
}
