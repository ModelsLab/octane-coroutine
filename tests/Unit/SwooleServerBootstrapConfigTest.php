<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SwooleServerBootstrapConfigTest extends TestCase
{
    public function test_swoole_server_reads_tick_from_swoole_config_namespace(): void
    {
        $server = file_get_contents(dirname(__DIR__, 2).'/bin/swoole-server');

        $this->assertStringContainsString("\$serverState['octaneConfig']['swoole']['tick'] ?? false", $server);
        $this->assertStringNotContainsString("\$serverState['octaneConfig']['tick'] ?? true", $server);
    }
}
