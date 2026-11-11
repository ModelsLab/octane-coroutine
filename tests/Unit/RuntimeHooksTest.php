<?php

namespace Tests\Unit;

use Laravel\Octane\Swoole\Coroutine\RuntimeHooks;
use PHPUnit\Framework\TestCase;

class RuntimeHooksTest extends TestCase
{
    public function test_default_flags_exclude_file_and_stdio_hooks(): void
    {
        if (! extension_loaded('swoole') || ! defined('SWOOLE_HOOK_ALL') || ! defined('SWOOLE_HOOK_FILE')) {
            $this->markTestSkipped('Swoole extension is required.');
        }

        $flags = RuntimeHooks::flags();

        $this->assertSame(0, $flags & SWOOLE_HOOK_FILE);

        if (defined('SWOOLE_HOOK_STDIO')) {
            $this->assertSame(0, $flags & SWOOLE_HOOK_STDIO);
        }

        $this->assertNotSame(0, $flags);
        $this->assertNotSame(SWOOLE_HOOK_ALL, $flags);
    }

    public function test_hook_file_can_be_reenabled_via_config(): void
    {
        if (! extension_loaded('swoole') || ! defined('SWOOLE_HOOK_FILE')) {
            $this->markTestSkipped('Swoole extension is required.');
        }

        $flags = RuntimeHooks::flags(['hook_file' => true]);

        $this->assertNotSame(0, $flags & SWOOLE_HOOK_FILE);
    }

    public function test_explicit_hook_flags_override_defaults(): void
    {
        if (! extension_loaded('swoole') || ! defined('SWOOLE_HOOK_TCP')) {
            $this->markTestSkipped('Swoole extension is required.');
        }

        $this->assertSame(SWOOLE_HOOK_TCP, RuntimeHooks::flags([
            'hook_flags' => SWOOLE_HOOK_TCP,
            'hook_file' => true,
        ]));
    }
    public function test_enable_skips_when_enable_coroutine_is_false(): void
    {
        if (! extension_loaded('swoole') || ! class_exists(\Swoole\Runtime::class)) {
            $this->markTestSkipped('Swoole extension is required.');
        }

        \Swoole\Runtime::enableCoroutine(0);
        \Laravel\Octane\Swoole\Coroutine\RuntimeHooks::enable([
            'options' => ['enable_coroutine' => false],
            'hook_file' => true,
        ]);

        $this->assertSame(0, \Swoole\Runtime::getHookFlags());
    }

}
