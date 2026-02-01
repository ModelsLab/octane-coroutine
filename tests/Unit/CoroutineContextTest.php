<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Laravel\Octane\Swoole\Coroutine\Context;

/**
 * Tests for the coroutine Context class.
 */
class CoroutineContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Context::clear();
    }

    protected function tearDown(): void
    {
        Context::clear();
        parent::tearDown();
    }

    public function test_can_set_and_get_values_in_global_context()
    {
        Context::set('test.key', 'test_value');

        $this->assertEquals('test_value', Context::get('test.key'));
    }

    public function test_returns_default_for_missing_key()
    {
        $this->assertNull(Context::get('nonexistent'));
        $this->assertEquals('default', Context::get('nonexistent', 'default'));
    }

    public function test_has_returns_correct_boolean()
    {
        $this->assertFalse(Context::has('test.key'));

        Context::set('test.key', 'value');

        $this->assertTrue(Context::has('test.key'));
    }

    public function test_delete_removes_key()
    {
        Context::set('test.key', 'value');
        $this->assertTrue(Context::has('test.key'));

        Context::delete('test.key');

        $this->assertFalse(Context::has('test.key'));
    }

    public function test_clear_removes_all_keys()
    {
        Context::set('key1', 'value1');
        Context::set('key2', 'value2');
        Context::set('key3', 'value3');

        Context::clear();

        $this->assertFalse(Context::has('key1'));
        $this->assertFalse(Context::has('key2'));
        $this->assertFalse(Context::has('key3'));
    }

    public function test_all_returns_all_context_data()
    {
        Context::set('key1', 'value1');
        Context::set('key2', 'value2');

        $all = Context::all();

        $this->assertArrayHasKey('key1', $all);
        $this->assertArrayHasKey('key2', $all);
        $this->assertEquals('value1', $all['key1']);
        $this->assertEquals('value2', $all['key2']);
    }

    public function test_id_returns_negative_when_not_in_coroutine()
    {
        // Outside of Swoole, getCid returns -1
        $this->assertEquals(-1, Context::id());
    }

    public function test_in_coroutine_returns_false_when_not_in_coroutine()
    {
        $this->assertFalse(Context::inCoroutine());
    }

    public function test_context_isolated_between_coroutines()
    {
        if (!class_exists(\Swoole\Coroutine::class) || !function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $results = [];

        \Swoole\Coroutine\run(function () use (&$results) {
            \Swoole\Coroutine::create(function () use (&$results) {
                Context::set('key', 'alpha');
                \Swoole\Coroutine::sleep(0.01);
                $results[] = Context::get('key');
            });

            \Swoole\Coroutine::create(function () use (&$results) {
                Context::set('key', 'bravo');
                \Swoole\Coroutine::sleep(0.02);
                $results[] = Context::get('key');
            });
        });

        sort($results);
        $this->assertSame(['alpha', 'bravo'], $results);
    }

    public function test_global_context_is_not_visible_inside_coroutine()
    {
        Context::set('global.key', 'global');

        if (!class_exists(\Swoole\Coroutine::class) || !function_exists('Swoole\\Coroutine\\run')) {
            Context::delete('global.key');
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $result = null;

        \Swoole\Coroutine\run(function () use (&$result) {
            $result = Context::get('global.key');
        });

        Context::delete('global.key');

        $this->assertNull($result);
    }
}
