<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

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

    private function sample(Request $request, string $secret = self::SECRET): float
    {
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $sampler = new SentryTracesSampler($secret, self::DEFAULT_RATE, $requestStack);

        return ($sampler->__invoke())(SamplingContext::getDefault(new TransactionContext()));
    }

    /**
     * @return array{0: Request, 1: Session}
     */
    private function createRequest(string $uri, bool $previousSession): array
    {
        $request = Request::create($uri);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        if ($previousSession) {
            $request->cookies->set($session->getName(), 'existing-session-id');
        }

        return [$request, $session];
    }
}
