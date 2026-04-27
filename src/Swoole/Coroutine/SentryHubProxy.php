<?php

namespace Laravel\Octane\Swoole\Coroutine;

use Sentry\Breadcrumb;
use Sentry\CheckInStatus;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventId;
use Sentry\Integration\IntegrationInterface;
use Sentry\MonitorConfig;
use Sentry\Severity;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

class SentryHubProxy implements HubInterface
{
    public const CONTEXT_KEY = 'octane.sentry_hub';

    public function __construct(private HubInterface $baseHub)
    {
    }

    public function seedCurrentCoroutineHub(): HubInterface
    {
        $hub = new Hub($this->baseHub->getClient());

        Context::set(self::CONTEXT_KEY, $hub);

        return $hub;
    }

    public function currentHub(): HubInterface
    {
        $hub = Context::get(self::CONTEXT_KEY);

        return $hub instanceof HubInterface ? $hub : $this->baseHub;
    }

    public function getClient(): ?ClientInterface
    {
        return $this->currentHub()->getClient();
    }

    public function getLastEventId(): ?EventId
    {
        return $this->currentHub()->getLastEventId();
    }

    public function pushScope(): Scope
    {
        return $this->currentHub()->pushScope();
    }

    public function popScope(): bool
    {
        return $this->currentHub()->popScope();
    }

    public function withScope(callable $callback)
    {
        return $this->currentHub()->withScope($callback);
    }

    public function configureScope(callable $callback): void
    {
        $this->currentHub()->configureScope($callback);
    }

    public function bindClient(ClientInterface $client): void
    {
        $this->baseHub->bindClient($client);

        if (($hub = Context::get(self::CONTEXT_KEY)) instanceof HubInterface) {
            $hub->bindClient($client);
        }
    }

    public function captureMessage(string $message, ?Severity $level = null, ?EventHint $hint = null): ?EventId
    {
        return $this->currentHub()->captureMessage($message, $level, $hint);
    }

    public function captureException(\Throwable $exception, ?EventHint $hint = null): ?EventId
    {
        return $this->currentHub()->captureException($exception, $hint);
    }

    public function captureEvent(Event $event, ?EventHint $hint = null): ?EventId
    {
        return $this->currentHub()->captureEvent($event, $hint);
    }

    public function captureLastError(?EventHint $hint = null): ?EventId
    {
        return $this->currentHub()->captureLastError($hint);
    }

    public function addBreadcrumb(Breadcrumb $breadcrumb): bool
    {
        return $this->currentHub()->addBreadcrumb($breadcrumb);
    }

    public function captureCheckIn(string $slug, CheckInStatus $status, $duration = null, ?MonitorConfig $monitorConfig = null, ?string $checkInId = null): ?string
    {
        return $this->currentHub()->captureCheckIn($slug, $status, $duration, $monitorConfig, $checkInId);
    }

    public function getIntegration(string $className): ?IntegrationInterface
    {
        return $this->currentHub()->getIntegration($className);
    }

    public function startTransaction(TransactionContext $context, array $customSamplingContext = []): Transaction
    {
        return $this->currentHub()->startTransaction($context, $customSamplingContext);
    }

    public function getTransaction(): ?Transaction
    {
        return $this->currentHub()->getTransaction();
    }

    public function getSpan(): ?Span
    {
        return $this->currentHub()->getSpan();
    }

    public function setSpan(?Span $span): HubInterface
    {
        $this->currentHub()->setSpan($span);

        return $this;
    }
}
