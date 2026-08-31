<?php

namespace Tests\Unit;

use Tests\TestCase;

class ConsoleSmokeTest extends TestCase
{
    public function test_octane_commands_are_registered_and_definable(): void
    {
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();

        $all = $kernel->all();

        foreach (['octane:install', 'octane:start', 'octane:reload', 'octane:status', 'octane:stop'] as $name) {
            $this->assertArrayHasKey($name, $all, "Missing command [$name].");

            // Touching the definition compiles every option/argument through
            // Symfony Console, which Laravel 13 bumps to a new major.
            $definition = $all[$name]->getDefinition();
            $this->assertNotEmpty($definition->getOptions());
        }
    }
}
