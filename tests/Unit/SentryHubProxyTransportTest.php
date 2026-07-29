<?php

namespace {
    require_once __DIR__.'/../Fixtures/sentry_stubs.php';
}

namespace Tests\Unit {
    use Laravel\Octane\Swoole\Coroutine\Context;
    use Laravel\Octane\Swoole\Coroutine\SentryHubProxy;
    use PHPUnit\Framework\TestCase;
    use Sentry\EventHint;
    use Sentry\EventId;
    use Sentry\Severity;
    use Sentry\State\Hub;

    /**
     * Sentry's transport sends events with curl_exec. Once Swoole's runtime
     * hooks are installed that function is coroutine-only, so reporting a fatal
     * from the shutdown handler -- which runs outside any coroutine -- raised
     * Swoole\Error "API must be called in the coroutine" and replaced the
     * original error in the logs with a second fatal.
     */
    class SentryHubProxyTransportTest extends TestCase
    {
        private ?int $originalHookFlags = null;

        protected function setUp(): void
        {
            parent::setUp();

            if (! extension_loaded('swoole')) {
                $this->markTestSkipped('Swoole is required to exercise the curl hook handling.');
            }

            $this->originalHookFlags = \Swoole\Runtime::getHookFlags();
        }

        protected function tearDown(): void
        {
            if ($this->originalHookFlags !== null) {
                \Swoole\Runtime::setHookFlags($this->originalHookFlags);
            }

            Context::clear();

            parent::tearDown();
        }

        public function test_curl_is_unhooked_for_captures_outside_a_coroutine(): void
        {
            \Swoole\Runtime::setHookFlags(SWOOLE_HOOK_NATIVE_CURL | SWOOLE_HOOK_TCP);

            $hub = new RecordingHub;
            $proxy = new SentryHubProxy($hub);

            $proxy->captureException(new \RuntimeException('boom'));

            $this->assertNotNull($hub->hookFlagsDuringCapture);
            $this->assertSame(
                0,
                $hub->hookFlagsDuringCapture & SWOOLE_HOOK_NATIVE_CURL,
                'Native curl must be unhooked while the event is sent outside a coroutine.'
            );

            // Unrelated hooks stay untouched, and everything is restored after.
            $this->assertSame(SWOOLE_HOOK_TCP, $hub->hookFlagsDuringCapture & SWOOLE_HOOK_TCP);
            $this->assertSame(SWOOLE_HOOK_NATIVE_CURL | SWOOLE_HOOK_TCP, \Swoole\Runtime::getHookFlags());
        }

        public function test_hooks_are_left_alone_for_captures_inside_a_coroutine(): void
        {
            \Swoole\Runtime::setHookFlags(SWOOLE_HOOK_NATIVE_CURL);

            $hub = new RecordingHub;
            $proxy = new SentryHubProxy($hub);

            \Swoole\Coroutine\run(function () use ($proxy) {
                $proxy->captureMessage('inside');
            });

            $this->assertSame(
                SWOOLE_HOOK_NATIVE_CURL,
                $hub->hookFlagsDuringCapture & SWOOLE_HOOK_NATIVE_CURL,
                'Inside a coroutine the hooked implementation is the correct one.'
            );
        }

        public function test_a_failing_transport_never_propagates(): void
        {
            $hub = new RecordingHub;
            $hub->throwOnCapture = new \RuntimeException('transport exploded');

            $proxy = new SentryHubProxy($hub);

            $this->assertNull($proxy->captureException(new \RuntimeException('original')));
            $this->assertNull($proxy->captureMessage('m'));
            $this->assertNull($proxy->captureLastError());
        }

        public function test_hooks_are_restored_even_when_the_capture_throws(): void
        {
            \Swoole\Runtime::setHookFlags(SWOOLE_HOOK_NATIVE_CURL);

            $hub = new RecordingHub;
            $hub->throwOnCapture = new \RuntimeException('transport exploded');

            (new SentryHubProxy($hub))->captureException(new \RuntimeException('original'));

            $this->assertSame(SWOOLE_HOOK_NATIVE_CURL, \Swoole\Runtime::getHookFlags());
        }
    }

    class RecordingHub extends Hub
    {
        public ?int $hookFlagsDuringCapture = null;

        public ?\Throwable $throwOnCapture = null;

        public function captureException(\Throwable $exception, ?EventHint $hint = null): ?EventId
        {
            return $this->record();
        }

        public function captureMessage(string $message, ?Severity $level = null, ?EventHint $hint = null): ?EventId
        {
            return $this->record();
        }

        public function captureLastError(?EventHint $hint = null): ?EventId
        {
            return $this->record();
        }

        private function record(): ?EventId
        {
            $this->hookFlagsDuringCapture = \Swoole\Runtime::getHookFlags();

            if ($this->throwOnCapture !== null) {
                throw $this->throwOnCapture;
            }

            return null;
        }
    }
}
