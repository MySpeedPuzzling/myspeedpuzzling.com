<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\EventSubscriber\BotTrustCookieSubscriber;
use SpeedPuzzling\Web\Services\BotTrustCookieSigner;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class BotTrustCookieSubscriberTest extends TestCase
{
    private const string SECRET = 'test-secret-for-unit-tests';

    /**
     * GOLDEN VECTOR — frozen cross-language constant. The bot-blocker sidecar
     * pins the exact same string in its test suite (test/trust.test.js in
     * MySpeedPuzzling/bot-blocker-middleware); if either side changes the wire
     * format, its golden test breaks before production does.
     */
    private const string GOLDEN_UID = 'a1b2c3d4e5f60718';
    private const int GOLDEN_IAT_MS = 1754000000000;
    private const string GOLDEN_COOKIE = 'YmItdHJ1c3R8djF8YTFiMmMzZDRlNWY2MDcxOHwxNzU0MDAwMDAwMDAw.'
        . '1RPyyufdp2q0_zj8wCHUH-pO3441xsojxNs65nh97sQ';

    public function testGoldenVectorMatchesTheSidecarImplementation(): void
    {
        $signer = new BotTrustCookieSigner(self::SECRET, new MockClock());

        self::assertSame(self::GOLDEN_COOKIE, $signer->buildAt(self::GOLDEN_UID, self::GOLDEN_IAT_MS));
    }

    public function testBuildRoundTripsThroughParse(): void
    {
        $clock = new MockClock('2026-08-02 12:00:00');
        $signer = new BotTrustCookieSigner(self::SECRET, $clock);
        $uid = BotTrustCookieSigner::uidFor('jan@example.com');

        $cookie = $signer->build($uid);

        self::assertSame((int) $clock->now()->format('Uv'), $signer->parseIssuedAt($cookie, $uid));
        self::assertNull($signer->parseIssuedAt($cookie, 'someone-else'));
        self::assertNull($signer->parseIssuedAt('garbage', $uid));
        self::assertNull($signer->parseIssuedAt($cookie . 'x', $uid));
    }

    public function testMintsCookieForAuthenticatedUser(): void
    {
        $event = $this->dispatchResponse(authenticated: true);

        $cookies = $event->getResponse()->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame(BotTrustCookieSigner::COOKIE_NAME, $cookies[0]->getName());
        self::assertTrue($cookies[0]->isHttpOnly());
        self::assertSame('/', $cookies[0]->getPath());

        $signer = new BotTrustCookieSigner(self::SECRET, new MockClock());
        $uid = BotTrustCookieSigner::uidFor('jan@example.com');
        self::assertNotNull($signer->parseIssuedAt((string) $cookies[0]->getValue(), $uid));
    }

    public function testAnonymousResponseGetsNoCookie(): void
    {
        $event = $this->dispatchResponse(authenticated: false);

        self::assertSame([], $event->getResponse()->headers->getCookies());
    }

    public function testFreshCookieIsNotReminted(): void
    {
        $signer = new BotTrustCookieSigner(self::SECRET, new MockClock());
        $uid = BotTrustCookieSigner::uidFor('jan@example.com');
        $fresh = $signer->build($uid);

        $event = $this->dispatchResponse(authenticated: true, existingCookie: $fresh);

        self::assertSame([], $event->getResponse()->headers->getCookies());
    }

    public function testStaleCookieIsReminted(): void
    {
        $clock = new MockClock();
        $signer = new BotTrustCookieSigner(self::SECRET, $clock);
        $uid = BotTrustCookieSigner::uidFor('jan@example.com');
        $staleIat = (int) $clock->now()->format('Uv')
            - (BotTrustCookieSigner::REFRESH_AFTER_DAYS + 1) * 24 * 60 * 60 * 1000;
        $stale = $signer->buildAt($uid, $staleIat);

        $event = $this->dispatchResponse(authenticated: true, existingCookie: $stale);

        self::assertCount(1, $event->getResponse()->headers->getCookies());
    }

    public function testForeignAccountCookieIsReminted(): void
    {
        $signer = new BotTrustCookieSigner(self::SECRET, new MockClock());
        $foreign = $signer->build(BotTrustCookieSigner::uidFor('someone-else@example.com'));

        $event = $this->dispatchResponse(authenticated: true, existingCookie: $foreign);

        self::assertCount(1, $event->getResponse()->headers->getCookies());
    }

    public function testDisabledEntirelyWithoutSecret(): void
    {
        $event = $this->dispatchResponse(authenticated: true, secret: '');

        self::assertSame([], $event->getResponse()->headers->getCookies());
    }

    public function testSubRequestsAreIgnored(): void
    {
        $event = $this->dispatchResponse(authenticated: true, requestType: HttpKernelInterface::SUB_REQUEST);

        self::assertSame([], $event->getResponse()->headers->getCookies());
    }

    private function dispatchResponse(
        bool $authenticated,
        null|string $existingCookie = null,
        string $secret = self::SECRET,
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
    ): ResponseEvent {
        $signer = new BotTrustCookieSigner($secret, new MockClock());

        $tokenStorage = new TokenStorage();
        if ($authenticated) {
            $user = new InMemoryUser('jan@example.com', 'irrelevant', ['ROLE_USER']);
            $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        }

        $subscriber = new BotTrustCookieSubscriber($signer, $tokenStorage, new MockClock());

        $request = Request::create('/muj-profil');
        if ($existingCookie !== null) {
            $request->cookies->set(BotTrustCookieSigner::COOKIE_NAME, $existingCookie);
        }

        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            $requestType,
            new Response(),
        );

        $subscriber->onKernelResponse($event);

        return $event;
    }
}
