<?php

/**
 * Probe: concurrent coroutines throttling through Illuminate's RateLimiter.
 *
 * The framework registers RateLimiter as a singleton holding a cache Repository
 * built at boot, so its RedisStore points at the worker-level Redis manager. In
 * a Swoole worker every concurrent request then throttles through one hooked
 * phpredis socket, and Swoole kills the process with
 * "Socket#N has already been bound to another coroutine#M".
 *
 * Two independent defences are exercised via separate flags:
 *
 *   --manager=stock|coroutine   which Redis manager the application binds
 *   --resolve=base|scope        whether the limiter is resolved from the base
 *                               application or through RequestScope, the way
 *                               Worker::handle does
 *
 * stock+base reproduces the production fatal; either defence alone prevents it.
 *
 * A socket is only "bound" while a coroutine is parked waiting on a reply. In
 * production that window is the network round-trip to Redis; against a local
 * server the replies come back too fast to interleave, so one coroutine parks
 * deliberately with a blocking BLPOP to make the race deterministic rather than
 * relying on timing.
 *
 * Prints a JSON summary then PROBE-COMPLETED, and exits 0 on success. The
 * Swoole error is raised by the scheduler rather than thrown through the
 * calling frame, so it cannot be caught: when it fires the process dies before
 * printing either line.
 */

require __DIR__.'/../../vendor/autoload.php';

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\RateLimiter;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Facade;
use Laravel\Octane\Swoole\Coroutine\Context;
use Laravel\Octane\Swoole\Coroutine\CoroutineApplication;
use Laravel\Octane\Swoole\Coroutine\RequestScope;
use Laravel\Octane\Swoole\Redis\CoroutineRedisManager;

$options = getopt('', ['manager::', 'resolve::', 'host::', 'port::', 'database::']);
$manager = $options['manager'] ?? 'coroutine';
$resolve = $options['resolve'] ?? 'scope';
$host = $options['host'] ?? '127.0.0.1';
$port = (int) ($options['port'] ?? 6379);
$database = (int) ($options['database'] ?? 15);

Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$redisConfig = [
    'client' => 'phpredis',
    'options' => [],
    'default' => [
        'host' => $host,
        'port' => $port,
        'database' => $database,
        // Persistent sockets are shared process-wide by persistent_id, so
        // production configs like this one defeat per-coroutine isolation
        // unless the manager strips them.
        'persistent' => true,
        'persistent_id' => 'probe-default',
    ],
];

$app = new Application(__DIR__);

$app->instance('config', new ConfigRepository([
    'database' => ['redis' => $redisConfig],
    'cache' => [
        'default' => 'redis',
        'limiter' => null,
        'prefix' => 'probe',
        'stores' => [
            'redis' => ['driver' => 'redis', 'connection' => 'default'],
        ],
    ],
]));

$app->singleton('redis', fn ($app) => $manager === 'stock'
    ? new RedisManager($app, 'phpredis', $redisConfig)
    : new CoroutineRedisManager($app, 'phpredis', $redisConfig));

$app->singleton('cache', fn ($app) => new CacheManager($app));

// Exactly how Illuminate\Cache\CacheServiceProvider registers it: the
// repository is captured once, at boot, from the root application.
$app->singleton(RateLimiter::class, fn ($app) => new RateLimiter(
    $app->make('cache')->driver($app['config']->get('cache.limiter'))
));

// Force the boot-time capture before any coroutine runs, mirroring a warmed worker.
$app->make(RateLimiter::class);

$sandbox = new CoroutineApplication($app);
Container::setInstance($sandbox);
Facade::setFacadeApplication($sandbox);

// Each coroutine enters the request scope the same way Worker::handle does.
$enterRequestScope = function () use ($app, $resolve) {
    if ($resolve === 'base') {
        return;
    }

    Context::set('octane.request_scope', new RequestScope($app, Request::create('/probe')));
};

$limiterOf = fn () => $resolve === 'base'
    ? $app->make(RateLimiter::class)
    : $sandbox->make(RateLimiter::class);

$connectionOf = function (RateLimiter $limiter) {
    $property = new ReflectionProperty(RateLimiter::class, 'cache');
    $property->setAccessible(true);

    return $property->getValue($limiter)->getStore()->connection();
};

$result = ['manager' => $manager, 'resolve' => $resolve, 'sockets' => [], 'errors' => []];

Swoole\Coroutine\run(function () use ($enterRequestScope, $limiterOf, $connectionOf, &$result) {
    $parked = new Swoole\Coroutine\Channel(1);

    // "Request A": parks inside a read on the limiter's connection.
    Swoole\Coroutine::create(function () use ($enterRequestScope, $limiterOf, $connectionOf, $parked, &$result) {
        $enterRequestScope();

        $connection = $connectionOf($limiterOf());
        $result['sockets']['a'] = spl_object_id($connection->client());

        $parked->push(true);

        try {
            $connection->blpop(['octane:probe:never-pushed'], 2);
        } catch (Throwable $e) {
            $result['errors'][] = 'a: '.get_class($e).': '.$e->getMessage();
        }
    });

    // "Request B": throttles while request A is still parked.
    Swoole\Coroutine::create(function () use ($enterRequestScope, $limiterOf, $connectionOf, $parked, &$result) {
        $parked->pop();
        Swoole\Coroutine::sleep(0.15);

        $enterRequestScope();

        $limiter = $limiterOf();
        $result['sockets']['b'] = spl_object_id($connectionOf($limiter)->client());

        try {
            $limiter->tooManyAttempts('octane:probe:limiter', 5);
            $limiter->hit('octane:probe:limiter', 60);
        } catch (Throwable $e) {
            $result['errors'][] = 'b: '.get_class($e).': '.$e->getMessage();
        }
    });
});

$result['shared_socket'] = ($result['sockets']['a'] ?? null) === ($result['sockets']['b'] ?? null);

echo json_encode($result)."\n";
echo "PROBE-COMPLETED\n";
