<?php

namespace Sentry {
    if (! interface_exists(ClientInterface::class)) {
        interface ClientInterface
        {
        }
    }

    if (! class_exists(Breadcrumb::class)) {
        class Breadcrumb
        {
            public function __construct(public string $message = '')
            {
            }
        }
    }

    if (! class_exists(CheckInStatus::class)) {
        class CheckInStatus
        {
        }
    }

    if (! class_exists(Event::class)) {
        class Event
        {
        }
    }

    if (! class_exists(EventHint::class)) {
        class EventHint
        {
        }
    }

    if (! class_exists(EventId::class)) {
        class EventId
        {
        }
    }

    if (! class_exists(MonitorConfig::class)) {
        class MonitorConfig
        {
        }
    }

    if (! class_exists(Severity::class)) {
        class Severity
        {
        }
    }
}

namespace Sentry\Integration {
    if (! interface_exists(IntegrationInterface::class)) {
        interface IntegrationInterface
        {
        }
    }
}

namespace Sentry\Tracing {
    if (! class_exists(Span::class)) {
        class Span
        {
        }
    }

    if (! class_exists(Transaction::class)) {
        class Transaction extends Span
        {
        }
    }

    if (! class_exists(TransactionContext::class)) {
        class TransactionContext
        {
        }
    }
}

namespace Sentry\State {
    use Sentry\Breadcrumb;
    use Sentry\CheckInStatus;
    use Sentry\ClientInterface;
    use Sentry\Event;
    use Sentry\EventHint;
    use Sentry\EventId;
    use Sentry\Integration\IntegrationInterface;
    use Sentry\MonitorConfig;
    use Sentry\Severity;
    use Sentry\Tracing\Span;
    use Sentry\Tracing\Transaction;
    use Sentry\Tracing\TransactionContext;

    if (! class_exists(Scope::class)) {
        class Scope
        {
            public array $breadcrumbs = [];
        }
    }

    if (! interface_exists(HubInterface::class)) {
        interface HubInterface
        {
            public function getClient(): ?ClientInterface;

            public function getLastEventId(): ?EventId;

            public function pushScope(): Scope;

            public function popScope(): bool;

            public function withScope(callable $callback);

            public function configureScope(callable $callback): void;

            public function bindClient(ClientInterface $client): void;

            public function captureMessage(string $message, ?Severity $level = null, ?EventHint $hint = null): ?EventId;

            public function captureException(\Throwable $exception, ?EventHint $hint = null): ?EventId;

            public function captureEvent(Event $event, ?EventHint $hint = null): ?EventId;

            public function captureLastError(?EventHint $hint = null): ?EventId;

            public function addBreadcrumb(Breadcrumb $breadcrumb): bool;

            public function captureCheckIn(string $slug, CheckInStatus $status, $duration = null, ?MonitorConfig $monitorConfig = null, ?string $checkInId = null): ?string;

            public function getIntegration(string $className): ?IntegrationInterface;

            public function startTransaction(TransactionContext $context, array $customSamplingContext = []): Transaction;

            public function getTransaction(): ?Transaction;

            public function getSpan(): ?Span;

            public function setSpan(?Span $span): HubInterface;
        }
    }

    if (! class_exists(Hub::class)) {
        class Hub implements HubInterface
        {
            public array $breadcrumbs = [];

            private array $stack;

            private ?Span $span = null;

            public function __construct(private ?ClientInterface $client = null, ?Scope $scope = null)
            {
                $this->stack = [$scope ?? new Scope];
            }

            public function getClient(): ?ClientInterface
            {
                return $this->client;
            }

            public function getLastEventId(): ?EventId
            {
                return null;
            }

            public function pushScope(): Scope
            {
                return $this->stack[] = new Scope;
            }

            public function popScope(): bool
            {
                if (count($this->stack) === 1) {
                    return false;
                }

                array_pop($this->stack);

                return true;
            }

            public function withScope(callable $callback)
            {
                $scope = $this->pushScope();

                try {
                    return $callback($scope);
                } finally {
                    $this->popScope();
                }
            }

            public function configureScope(callable $callback): void
            {
                $callback($this->stack[array_key_last($this->stack)]);
            }

            public function bindClient(ClientInterface $client): void
            {
                $this->client = $client;
            }

            public function captureMessage(string $message, ?Severity $level = null, ?EventHint $hint = null): ?EventId
            {
                return null;
            }

            public function captureException(\Throwable $exception, ?EventHint $hint = null): ?EventId
            {
                return null;
            }

            public function captureEvent(Event $event, ?EventHint $hint = null): ?EventId
            {
                return null;
            }

            public function captureLastError(?EventHint $hint = null): ?EventId
            {
                return null;
            }

            public function addBreadcrumb(Breadcrumb $breadcrumb): bool
            {
                $this->breadcrumbs[] = $breadcrumb;
                $this->stack[array_key_last($this->stack)]->breadcrumbs[] = $breadcrumb;

                return true;
            }

            public function captureCheckIn(string $slug, CheckInStatus $status, $duration = null, ?MonitorConfig $monitorConfig = null, ?string $checkInId = null): ?string
            {
                return null;
            }

            public function getIntegration(string $className): ?IntegrationInterface
            {
                return null;
            }

            public function startTransaction(TransactionContext $context, array $customSamplingContext = []): Transaction
            {
                return new Transaction;
            }

            public function getTransaction(): ?Transaction
            {
                return $this->span instanceof Transaction ? $this->span : null;
            }

            public function getSpan(): ?Span
            {
                return $this->span;
            }

            public function setSpan(?Span $span): HubInterface
            {
                $this->span = $span;

                return $this;
            }
        }
    }
}

namespace Sentry {
    use Sentry\State\Hub;
    use Sentry\State\HubInterface;

    if (! class_exists(SentrySdk::class)) {
        final class SentrySdk
        {
            private static ?HubInterface $currentHub = null;

            public static function getCurrentHub(): HubInterface
            {
                return self::$currentHub ??= new Hub;
            }

            public static function setCurrentHub(HubInterface $hub): HubInterface
            {
                return self::$currentHub = $hub;
            }
        }
    }
}

namespace Sentry\Laravel\Tracing {
    if (! class_exists(Middleware::class)) {
        class Middleware
        {
            protected $transaction = null;

            protected $appSpan = null;

            private ?float $bootedTimestamp = null;

            private bool $didRouteMatch = false;

            private bool $continueAfterResponse;

            public function __construct(bool $continueAfterResponse = true)
            {
                $this->continueAfterResponse = $continueAfterResponse;
            }

            public function markDirty(): void
            {
                $this->transaction = new \stdClass;
                $this->appSpan = new \stdClass;
                $this->bootedTimestamp = microtime(true);
                $this->didRouteMatch = true;
            }
        }
    }
}

namespace Tests\Unit {
    use Illuminate\Foundation\Application;
    use Laravel\Octane\Swoole\Coroutine\Context;
    use Laravel\Octane\Swoole\Coroutine\RequestScope;
    use Laravel\Octane\Swoole\Coroutine\SentryHubProxy;
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;
    use Sentry\Breadcrumb;
    use Sentry\Laravel\Tracing\Middleware;
    use Sentry\SentrySdk;
    use Sentry\State\Hub;
    use Sentry\State\HubInterface;

    class RequestScopeSentryIsolationTest extends TestCase
    {
        protected function tearDown(): void
        {
            Context::clear();
            SentrySdk::setCurrentHub(new Hub);

            parent::tearDown();
        }

        public function test_sentry_sdk_static_hub_delegates_to_a_fresh_coroutine_hub_per_scope(): void
        {
            $baseHub = new Hub;
            $base = new Application(__DIR__);
            $base->instance('sentry', $baseHub);
            SentrySdk::setCurrentHub($baseHub);

            new RequestScope($base);

            $proxy = SentrySdk::getCurrentHub();
            $firstHub = Context::get(SentryHubProxy::CONTEXT_KEY);

            $this->assertInstanceOf(SentryHubProxy::class, $proxy);
            $this->assertInstanceOf(HubInterface::class, $firstHub);
            $this->assertNotSame($baseHub, $firstHub);

            $proxy->addBreadcrumb(new Breadcrumb('first-request'));

            $this->assertCount(0, $baseHub->breadcrumbs);
            $this->assertCount(1, $firstHub->breadcrumbs);

            new RequestScope($base);

            $secondHub = Context::get(SentryHubProxy::CONTEXT_KEY);

            $this->assertNotSame($firstHub, $secondHub);
            $this->assertCount(0, $secondHub->breadcrumbs);

            $proxy->addBreadcrumb(new Breadcrumb('second-request'));

            $this->assertCount(1, $firstHub->breadcrumbs);
            $this->assertCount(1, $secondHub->breadcrumbs);
            $this->assertCount(0, $baseHub->breadcrumbs);
        }

        public function test_sentry_tracing_middleware_is_request_scoped_and_reset(): void
        {
            $base = new Application(__DIR__);
            $sandbox = new Application(__DIR__);
            $baseMiddleware = new Middleware(false);
            $baseMiddleware->markDirty();
            $base->instance(Middleware::class, $baseMiddleware);
            $scope = new RequestScope($base);

            $middleware = $scope->resolve(Middleware::class, $sandbox);

            $this->assertInstanceOf(Middleware::class, $middleware);
            $this->assertNotSame($baseMiddleware, $middleware);
            $this->assertFalse($this->readProperty($middleware, 'continueAfterResponse'));
            $this->assertNull($this->readProperty($middleware, 'transaction'));
            $this->assertNull($this->readProperty($middleware, 'appSpan'));
            $this->assertNull($this->readProperty($middleware, 'bootedTimestamp'));
            $this->assertFalse($this->readProperty($middleware, 'didRouteMatch'));
            $this->assertSame($middleware, $scope->resolve(Middleware::class, $sandbox));
        }

        private function readProperty(object $object, string $property): mixed
        {
            $reflection = new ReflectionProperty($object, $property);
            $reflection->setAccessible(true);

            return $reflection->getValue($object);
        }
    }
}
