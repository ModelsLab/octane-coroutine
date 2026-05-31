<?php

namespace Tests\Unit;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactoryContract;
use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Application;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

class RequestScopeFilesystemIsolationTest extends TestCase
{
    public function test_filesystem_manager_is_request_scoped_and_does_not_reuse_worker_disks(): void
    {
        $base = $this->applicationWithFilesystem();
        $baseFilesystem = $base->make('filesystem');
        $baseDisk = $baseFilesystem->disk('local');

        $scope = new RequestScope($base);
        $sandbox = new CoroutineApplication($base);

        $scopedFilesystem = $scope->resolve('filesystem', $sandbox);

        $this->assertInstanceOf(FilesystemManager::class, $scopedFilesystem);
        $this->assertNotSame($baseFilesystem, $scopedFilesystem);
        $this->assertSame($sandbox, $this->readProperty($scopedFilesystem, 'app'));
        $this->assertSame([], $this->readProperty($scopedFilesystem, 'disks'));

        $scopedDisk = $scope->resolve('filesystem.disk', $sandbox);

        $this->assertInstanceOf(FilesystemContract::class, $scopedDisk);
        $this->assertNotSame($baseDisk, $scopedDisk);
        $this->assertSame($scopedFilesystem->disk('local'), $scopedDisk);
    }

    public function test_filesystem_aliases_resolve_to_the_same_request_scoped_manager(): void
    {
        $base = $this->applicationWithFilesystem();
        $scope = new RequestScope($base);
        $sandbox = new CoroutineApplication($base);

        $manager = $scope->resolve('filesystem', $sandbox);

        $this->assertSame($manager, $scope->resolve(FilesystemManager::class, $sandbox));
        $this->assertSame($manager, $scope->resolve(FilesystemFactoryContract::class, $sandbox));
    }

    public function test_clear_forgets_scoped_filesystem_disks(): void
    {
        $base = $this->applicationWithFilesystem();
        $filesystem = new TrackingFilesystemManager($base);

        $scope = new RequestScope($base);
        $scope->set('filesystem', $filesystem);

        $scope->clear();

        sort($filesystem->forgotten);

        $this->assertSame(['local', 's3'], $filesystem->forgotten);
    }

    public function test_coroutine_application_resolves_distinct_filesystems_per_coroutine(): void
    {
        if (! class_exists(Coroutine::class) || ! class_exists(Channel::class)) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $base = $this->applicationWithFilesystem();
        $sandbox = new CoroutineApplication($base);
        $results = [];

        Coroutine\run(function () use ($base, $sandbox, &$results) {
            $done = new Channel(2);

            for ($i = 0; $i < 2; $i++) {
                Coroutine::create(function () use ($base, $sandbox, $done) {
                    $scope = new RequestScope($base);

                    Context::set('octane.request_scope', $scope);

                    $filesystem = $sandbox->make('filesystem');
                    $disk = $sandbox->make('filesystem.disk');

                    $done->push([
                        'filesystem' => spl_object_id($filesystem),
                        'disk' => spl_object_id($disk),
                    ]);

                    $scope->clear();
                    Context::clear();
                });
            }

            for ($i = 0; $i < 2; $i++) {
                $results[] = $done->pop();
            }
        });

        $this->assertCount(2, $results);
        $this->assertNotSame($results[0]['filesystem'], $results[1]['filesystem']);
        $this->assertNotSame($results[0]['disk'], $results[1]['disk']);
    }

    private function applicationWithFilesystem(): Application
    {
        $app = new Application(__DIR__);

        $app->instance('config', new ConfigRepository([
            'filesystems' => [
                'default' => 'local',
                'cloud' => 's3',
                'disks' => [
                    'local' => [
                        'driver' => 'local',
                        'root' => __DIR__,
                    ],
                    's3' => [
                        'driver' => 'local',
                        'root' => __DIR__,
                    ],
                ],
            ],
        ]));

        $app->instance('filesystem', new FilesystemManager($app));

        return $app;
    }

    private function readProperty(object $object, string $property)
    {
        $reflection = new ReflectionClass($object);

        while (! $reflection->hasProperty($property) && $reflection->getParentClass()) {
            $reflection = $reflection->getParentClass();
        }

        $instanceProperty = $reflection->getProperty($property);
        $instanceProperty->setAccessible(true);

        return $instanceProperty->getValue($object);
    }
}

class TrackingFilesystemManager extends FilesystemManager
{
    /** @var array<int, string> */
    public array $forgotten = [];

    public function forgetDisk($disk)
    {
        foreach ((array) $disk as $diskName) {
            $this->forgotten[] = $diskName;
        }

        return $this;
    }
}
