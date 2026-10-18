<?php

namespace Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\ViewServiceProvider;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Runtime;

/**
 * BladeCompiler keeps the view it is compiling in $this->path. compile() sets
 * the path, reads the source (a hooked file read, so the coroutine yields),
 * then writes to getCompiledPath($this->getPath()). With one compiler shared
 * by every coroutine in a worker, a second request that compiled another view
 * meanwhile moved the path, and one view's compiled PHP landed in the other
 * view's cache file. In production Inertia's <x-inertia::app /> got
 * <x-inertia::head>'s code, so every page printed its head tags where the app
 * root belongs, until the container restarted.
 */
class RequestScopeBladeCompilerIsolationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/octane-blade-'.bin2hex(random_bytes(6));

        mkdir($this->directory.'/views', 0755, true);
        mkdir($this->directory.'/compiled', 0755, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_blade_compiler_is_request_scoped_and_keeps_boot_time_directives(): void
    {
        $base = $this->applicationWithViews();
        $baseCompiler = $base->make('blade.compiler');
        $baseCompiler->directive('shout', fn (string $expression): string => "<?php echo strtoupper({$expression}); ?>");

        $sandbox = new CoroutineApplication($base);

        Context::set('octane.request_scope', new RequestScope($base));

        try {
            $scopedCompiler = $sandbox->make('blade.compiler');

            $this->assertInstanceOf(BladeCompiler::class, $scopedCompiler);
            $this->assertNotSame($baseCompiler, $scopedCompiler);
            $this->assertSame($scopedCompiler, $sandbox->make(BladeCompiler::class));
            $this->assertArrayHasKey('shout', $scopedCompiler->getCustomDirectives());

            $engine = $sandbox->make('view')->getEngineResolver()->resolve('blade');

            $this->assertInstanceOf(CompilerEngine::class, $engine);
            $this->assertSame($scopedCompiler, $engine->getCompiler());
            $this->assertSame($baseCompiler, $base->make('view')->getEngineResolver()->resolve('blade')->getCompiler());
        } finally {
            Context::clear();
        }

        Context::set('octane.request_scope', new RequestScope($base));

        try {
            $this->assertNotSame($scopedCompiler, $sandbox->make('blade.compiler'));
        } finally {
            Context::clear();
        }
    }

    public function test_concurrent_requests_compiling_different_inline_views_keep_their_own_compiled_code(): void
    {
        if (! class_exists(Coroutine::class) || ! class_exists(Channel::class) || ! class_exists(Runtime::class)) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $base = $this->applicationWithViews();
        $sandbox = new CoroutineApplication($base);
        $results = [];

        // Wire the globals the way OnWorkerStart does, with every hook on, so the
        // compiler's file reads and writes yield like they do in a worker.
        $previousContainer = Container::getInstance();
        $previousFacadeApp = Facade::getFacadeApplication();
        $cached = new ReflectionProperty(Facade::class, 'cached');
        $previousCached = $cached->getValue();
        $previousHookFlags = Runtime::getHookFlags();

        Container::setInstance($sandbox);
        Facade::setFacadeApplication($sandbox);
        Facade::clearResolvedInstances();
        $cached->setValue(null, false);
        Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        try {
            Coroutine\run(function () use ($base, &$results) {
                $done = new Channel(20);

                // Ten pairs of requests: each pair renders two inline templates
                // (the shape of Inertia's head and app components) that nothing
                // has compiled yet, at the same time.
                for ($round = 0; $round < 10; $round++) {
                    foreach (['head', 'app'] as $part) {
                        Coroutine::create(function () use ($base, $done, $round, $part) {
                            $scope = new RequestScope($base);
                            Context::set('octane.request_scope', $scope);

                            $template = $part === 'head'
                                ? "<title>head {$round}</title>{{ \$marker }}"
                                : "<div id=\"app\">app {$round}</div>{{ \$marker }}";

                            try {
                                $output = Blade::render($template, ['marker' => "{$part}-{$round}"]);
                            } catch (\Throwable $e) {
                                $output = get_class($e).': '.$e->getMessage();
                            }

                            $done->push(["{$part}-{$round}" => $output]);

                            $scope->clear();
                            Context::clear();
                        });
                    }
                }

                for ($i = 0; $i < 20; $i++) {
                    $results += $done->pop();
                }
            });
        } finally {
            Runtime::setHookFlags($previousHookFlags);
            $cached->setValue(null, $previousCached);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacadeApp);
            Container::setInstance($previousContainer);
        }

        $expected = [];

        for ($round = 0; $round < 10; $round++) {
            $expected["head-{$round}"] = "<title>head {$round}</title>head-{$round}";
            $expected["app-{$round}"] = "<div id=\"app\">app {$round}</div>app-{$round}";
        }

        ksort($expected);
        ksort($results);

        $this->assertSame($expected, $results);
    }

    private function applicationWithViews(): Application
    {
        $app = new Application($this->directory);

        $app->instance('config', new Repository([
            'view' => [
                'paths' => [$this->directory.'/views'],
                'compiled' => $this->directory.'/compiled',
            ],
        ]));
        $app->instance('files', new Filesystem);
        $app->singleton('events', fn ($app) => new Dispatcher($app));

        (new ViewServiceProvider($app))->register();

        return $app;
    }
}
