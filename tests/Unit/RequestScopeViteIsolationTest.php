<?php

namespace Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Vite as ViteFacade;
use Laravel\Octane\Listeners\FlushVite;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * Vite records the assets @vite renders, and AddLinkHeadersForPreloadedAssets
 * turns that list into the Link header. On the shared singleton, concurrent
 * requests in one worker appended to one list, and FlushVite at the start of
 * every request emptied it for requests still in flight. Pages shipped another
 * page's preload links or none, and a page cache kept the wrong list.
 */
class RequestScopeViteIsolationTest extends TestCase
{
    public function test_vite_is_request_scoped_with_boot_configuration_and_an_empty_preload_list(): void
    {
        $base = $this->applicationWithVite();
        $baseVite = $base->make(Vite::class);
        $this->recordPreload($baseVite, '/build/assets/left-over.js');

        $scope = new RequestScope($base);
        $sandbox = new CoroutineApplication($base);

        $scopedVite = $scope->resolve(Vite::class, $sandbox);

        $this->assertInstanceOf(Vite::class, $scopedVite);
        $this->assertNotSame($baseVite, $scopedVite);
        $this->assertSame('custom-build', $this->readProperty($scopedVite, 'buildDirectory'));
        $this->assertSame([], $scopedVite->preloadedAssets());
        $this->assertSame($scopedVite, $scope->resolve(Vite::class, $sandbox));
    }

    public function test_a_request_that_starts_mid_render_does_not_touch_another_requests_preloads(): void
    {
        if (! class_exists(Coroutine::class) || ! class_exists(Channel::class)) {
            $this->markTestSkipped('Swoole coroutine support is required.');
        }

        $base = $this->applicationWithVite();
        $sandbox = new CoroutineApplication($base);
        $results = [];

        // Wire the globals the way OnWorkerStart does: Blade's @vite resolves through
        // app(), AddLinkHeadersForPreloadedAssets through the uncached Vite facade.
        $previousContainer = Container::getInstance();
        $previousFacadeApp = Facade::getFacadeApplication();
        $cached = new ReflectionProperty(Facade::class, 'cached');
        $previousCached = $cached->getValue();

        Container::setInstance($sandbox);
        Facade::setFacadeApplication($sandbox);
        Facade::clearResolvedInstances();
        $cached->setValue(null, false);

        try {
            Coroutine\run(function () use ($base, $sandbox, &$results) {
                $aRendered = new Channel(1);
                $bRendered = new Channel(1);
                $done = new Channel(2);

                // Request A renders its page, then yields before its Link header is built.
                Coroutine::create(function () use ($base, $sandbox, $aRendered, $bRendered, $done) {
                    $scope = new RequestScope($base);
                    Context::set('octane.request_scope', $scope);

                    (new FlushVite)->handle((object) ['sandbox' => $sandbox]);
                    $this->recordPreload(app(Vite::class), '/build/assets/page-a.js');

                    $aRendered->push(true);
                    $bRendered->pop();

                    $done->push(['a' => array_keys(ViteFacade::preloadedAssets())]);

                    $scope->clear();
                    Context::clear();
                });

                // Request B starts (RequestReceived runs FlushVite) and renders meanwhile.
                Coroutine::create(function () use ($base, $sandbox, $aRendered, $bRendered, $done) {
                    $aRendered->pop();

                    $scope = new RequestScope($base);
                    Context::set('octane.request_scope', $scope);

                    (new FlushVite)->handle((object) ['sandbox' => $sandbox]);
                    $this->recordPreload(app(Vite::class), '/build/assets/page-b.js');

                    $bRendered->push(true);

                    $done->push(['b' => array_keys(ViteFacade::preloadedAssets())]);

                    $scope->clear();
                    Context::clear();
                });

                for ($i = 0; $i < 2; $i++) {
                    $results += $done->pop();
                }
            });
        } finally {
            $cached->setValue(null, $previousCached);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacadeApp);
            Container::setInstance($previousContainer);
        }

        $this->assertSame(['/build/assets/page-a.js'], $results['a']);
        $this->assertSame(['/build/assets/page-b.js'], $results['b']);
        $this->assertSame([], $base->make(Vite::class)->preloadedAssets());
    }

    private function applicationWithVite(): Application
    {
        $app = new Application(__DIR__);
        $app->singleton(Vite::class);
        $app->make(Vite::class)->useBuildDirectory('custom-build');

        return $app;
    }

    /**
     * Record a preload the way rendering @vite does.
     */
    private function recordPreload(Vite $vite, string $url): void
    {
        $reflection = new ReflectionClass($vite);
        $property = $reflection->getProperty('preloadedAssets');

        $assets = $property->getValue($vite);
        $assets[$url] = ['rel="modulepreload"', 'as="script"'];

        $property->setValue($vite, $assets);
    }

    private function readProperty(object $object, string $property)
    {
        $reflection = new ReflectionClass($object);

        return $reflection->getProperty($property)->getValue($object);
    }
}
