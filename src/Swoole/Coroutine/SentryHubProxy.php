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
        return $this->send(fn () => $this->currentHub()->captureMessage($message, $level, $hint));
    }

    public function captureException(\Throwable $exception, ?EventHint $hint = null): ?EventId
    {
        return $this->send(fn () => $this->currentHub()->captureException($exception, $hint));
    }

    public function captureEvent(Event $event, ?EventHint $hint = null): ?EventId
    {
        return $this->send(fn () => $this->currentHub()->captureEvent($event, $hint));
    }

    public function captureLastError(?EventHint $hint = null): ?EventId
    {
        return $this->send(fn () => $this->currentHub()->captureLastError($hint));
    }

    public function addBreadcrumb(Breadcrumb $breadcrumb): bool
    {
        return $this->currentHub()->addBreadcrumb($breadcrumb);
    }

    public function captureCheckIn(string $slug, CheckInStatus $status, $duration = null, ?MonitorConfig $monitorConfig = null, ?string $checkInId = null): ?string
    {
        return $this->send(fn () => $this->currentHub()->captureCheckIn($slug, $status, $duration, $monitorConfig, $checkInId));
    }

    /**
     * Run a capture that may hit Sentry's HTTP transport.
     *
     * Sentry sends events with curl_exec. Once Swoole's runtime hooks are
     * installed, curl_exec is coroutine-only and raises
     * Swoole\Error "API must be called in the coroutine" when it runs outside
     * one. The framework's shutdown handler reports fatals from exactly there,
     * so an error being reported turns into a second, fatal error that replaces
     * the original in the logs.
     *
     * Reporting must never be able to take the worker down, so transport
     * failures are swallowed: losing one event beats losing the process.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn|null
     */
    private function send(callable $callback)
    {
        try {
            return $this->withUnhookedTransport($callback);
        } catch (\Throwable $e) {
            error_log('⚠️ Sentry capture failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Temporarily restore blocking curl when running outside a coroutine.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withUnhookedTransport(callable $callback)
    {
        if (! $this->transportNeedsUnhooking()) {
            return $callback();
        }

        $flags = \Swoole\Runtime::getHookFlags();

        try {
            \Swoole\Runtime::setHookFlags($flags & ~$this->curlHookMask());
        } catch (\Throwable $e) {
            // Could not adjust the hooks; try the call as-is.
            return $callback();
        }

        try {
            return $callback();
        } finally {
            try {
                \Swoole\Runtime::setHookFlags($flags);
            } catch (\Throwable $e) {
                // Nothing useful to do while unwinding.
            }
        }
    }

    /**
     * Determine whether hooked curl would fatal for the current call.
     */
    private function transportNeedsUnhooking(): bool
    {
        if (! class_exists('Swoole\\Runtime') || $this->curlHookMask() === 0) {
            return false;
        }

        // Inside a coroutine the hooked implementation is the correct one.
        if (Context::inCoroutine()) {
            return false;
        }

        return (\Swoole\Runtime::getHookFlags() & $this->curlHookMask()) !== 0;
    }

    /**
     * Every curl hook flag this Swoole build knows about.
     *
     * Swoole has shipped two curl hooks: SWOOLE_HOOK_CURL, which swaps in a
     * userland coroutine client, and the later SWOOLE_HOOK_NATIVE_CURL that
     * SWOOLE_HOOK_ALL enables. Both have raised "API must be called in the
     * coroutine" outside a coroutine on some versions, so neither is assumed.
     */
    private function curlHookMask(): int
    {
        $mask = 0;

        if (defined('SWOOLE_HOOK_NATIVE_CURL')) {
            $mask |= SWOOLE_HOOK_NATIVE_CURL;
        }

        if (defined('SWOOLE_HOOK_CURL')) {
            $mask |= SWOOLE_HOOK_CURL;
        }

        return $mask;
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
