<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepositoryContract;
use Illuminate\Database\Connectors\ConnectionFactory;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Database\DatabaseManager;
use Mockery;
use PHPUnit\Framework\TestCase;

class DatabaseManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        Context::clear();
        Mockery::close();
        parent::tearDown();
    }

    public function test_non_pdo_drivers_are_not_sent_through_the_coroutine_pool(): void
    {
        if (! class_exists(\Swoole\Coroutine::class) || ! function_exists('Swoole\\Coroutine\\run')) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $app = new Container;
        $config = new DatabaseManagerArrayConfig([
            'database' => [
                'default' => 'mongodb',
                'connections' => [
                    'mongodb' => ['driver' => 'mongodb'],
                ],
            ],
        ]);

        $factory = Mockery::mock(ConnectionFactory::class);
        $factory->shouldNotReceive('make');

        $manager = new DatabaseManagerFallbackProbe($app, $factory);
        $app->instance('config', $config);

        $connection = null;

        \Swoole\Coroutine\run(function () use ($manager, &$connection) {
            $connection = $manager->connection('mongodb');
        });

        $this->assertSame('parent:mongodb', $connection);
    }
}

class DatabaseManagerFallbackProbe extends DatabaseManager
{
    protected function syncApplication(): void
    {
    }

    public function connection($name = null)
    {
        $this->syncApplication();
        $name = $name ?: $this->getDefaultConnection();

        if (! Context::inCoroutine() || ! $this->shouldPoolConnection($name)) {
            return 'parent:'.$name;
        }

        return parent::connection($name);
    }
}

class DatabaseManagerArrayConfig implements ConfigRepositoryContract, \ArrayAccess
{
    public function __construct(private array $items)
    {
    }

    public function has($key)
    {
        return $this->get($key) !== null;
    }

    public function get($key, $default = null)
    {
        return data_get($this->items, $key, $default);
    }

    public function all()
    {
        return $this->items;
    }

    public function set($key, $value = null)
    {
        data_set($this->items, $key, $value);
    }

    public function prepend($key, $value)
    {
        $array = $this->get($key, []);
        array_unshift($array, $value);
        $this->set($key, $array);
    }

    public function push($key, $value)
    {
        $array = $this->get($key, []);
        $array[] = $value;
        $this->set($key, $array);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        data_forget($this->items, (string) $offset);
    }
}
