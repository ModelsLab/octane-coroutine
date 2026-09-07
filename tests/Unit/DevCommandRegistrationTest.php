<?php

namespace Tests\Unit;

use Illuminate\Foundation\DevCommands;
use Tests\TestCase;

class DevCommandRegistrationTest extends TestCase
{
    public function test_octane_replaces_the_default_dev_server_command(): void
    {
        if (! class_exists(DevCommands::class)) {
            $this->markTestSkipped('The "dev" command requires Laravel 13.');
        }

        $commands = collect(DevCommands::commands());

        $server = $commands->firstWhere('name', 'server');

        $this->assertNotNull($server, 'Octane did not register a "server" dev command.');
        $this->assertSame('php artisan octane:start --watch', $server['command']);
    }
}
