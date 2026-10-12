<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sentry\Tracing\SamplingContext;
use Sentry\Tracing\TransactionContext;
use SpeedPuzzling\Web\Services\SentryTracesSampler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class SentryTracesSamplerTest extends TestCase
{
    private const string SECRET = 'test-secret';
    private const float DEFAULT_RATE = 0.1;

    public function testDoesNotStartSessionForRequestWithoutSessionCookie(): void
    {
        // Anonymous first-time visitor: has a session factory, but no session cookie.
        // The sampler must not touch (= start) the session.
        [$request, $session] = $this->createRequest('/some-page', previousSession: false);

        $rate = $this->sample($request);

        self::assertSame(self::DEFAULT_RATE, $rate);
        self::assertFalse($session->isStarted());
    }

    public function testActivationViaSecretStartsSessionDeliberately(): void
    {
        [$request, $session] = $this->createRequest('/some-page?_profile=' . self::SECRET, previousSession: false);

        $rate = $this->sample($request);

        self::assertSame(1.0, $rate);
        self::assertSame(self::SECRET, $session->get('_sentry_profiler_enabled'));
    }

    public function testPersistedActivationInExistingSession(): void
    {
        [$request, $session] = $this->createRequest('/some-page', previousSession: true);
        $session->set('_sentry_profiler_enabled', self::SECRET);

        $rate = $this->sample($request);

        self::assertSame(1.0, $rate);
    }

    public function testMismatchedPersistedValueDoesNotActivate(): void
    {
        [$request, $session] = $this->createRequest('/some-page', previousSession: true);
        $session->set('_sentry_profiler_enabled', 'stale-or-forged');

        $rate = $this->sample($request);

        self::assertSame(self::DEFAULT_RATE, $rate);
    }

    public function testDeactivationRemovesFlagFromExistingSession(): void
    {
        [$request, $session] = $this->createRequest('/some-page?_profile=off', previousSession: true);
        $session->set('_sentry_profiler_enabled', self::SECRET);

        $rate = $this->sample($request);

        self::assertSame(self::DEFAULT_RATE, $rate);
        self::assertFalse($session->has('_sentry_profiler_enabled'));
    }

    public function testEmptySecretNeverActivatesNorTouchesSession(): void
    {
        [$request, $session] = $this->createRequest('/some-page?_profile=anything', previousSession: false);

        $rate = $this->sample($request, secret: '');

        self::assertSame(self::DEFAULT_RATE, $rate);
        self::assertFalse($session->isStarted());
    }

    /**
     * The /-/ beacons and health checks: never traced - not even with the profiling flag in the session, a sampled
     * parent or the secret in the query - and the signed-in visitor's session is never started (= read) to decide it.
     */
    #[DataProvider('telemetryPaths')]
    public function testTelemetryAndHealthChecksAreNeverTracedNorTouchTheSession(string $uri): void
    {
        [$request, $session] = $this->createRequest($uri, previousSession: true, storedData: ['_sentry_profiler_enabled' => self::SECRET]);

        $transaction = new TransactionContext();
        $transaction->setParentSampled(true);

        self::assertSame(0.0, $this->sample($request, transaction: $transaction));
        self::assertFalse($session->isStarted());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function telemetryPaths(): iterable
    {
        yield 'navigation click beacon' => ['/-/nav-click'];
        yield 'asset load failure beacon' => ['/-/asset-load-failure'];
        yield 'stale document beacon' => ['/-/stale-document'];
        yield 'liveness health check' => ['/-/health-check/liveness'];
        yield 'with the profiling secret' => ['/-/nav-click?_profile=' . self::SECRET];
    }

    /**
     * Other pages keep the persisted profiling flag - the check above is no blanket "never read the session".
     */
    public function testThePersistedFlagStillWorksOnPages(): void
    {
        [$request] = $this->createRequest('/en/-/not-telemetry', previousSession: true, storedData: ['_sentry_profiler_enabled' => self::SECRET]);

        self::assertSame(1.0, $this->sample($request));
    }

    private function sample(Request $request, string $secret = self::SECRET, null|TransactionContext $transaction = null): float
    {
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $sampler = new SentryTracesSampler($secret, self::DEFAULT_RATE, $requestStack);

        return ($sampler->__invoke())(SamplingContext::getDefault($transaction ?? new TransactionContext()));
    }

    /**
     * @param array<string, mixed> $storedData session attributes already stored - the session is not started
     * @return array{0: Request, 1: Session}
     */
    private function createRequest(string $uri, bool $previousSession, array $storedData = []): array
    {
        $request = Request::create($uri);
        $storage = new MockArraySessionStorage();
        $storage->setSessionData(['_sf2_attributes' => $storedData]);
        $session = new Session($storage);
        $request->setSession($session);

        if ($previousSession) {
            $request->cookies->set($session->getName(), 'existing-session-id');
        }

        return [$request, $session];
    }
}
