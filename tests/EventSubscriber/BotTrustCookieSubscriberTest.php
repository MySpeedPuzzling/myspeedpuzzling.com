<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\EventSubscriber\BotTrustCookieSubscriber;
use SpeedPuzzling\Web\Query\GetBotTrustEligibility;
use SpeedPuzzling\Web\Services\BotTrustCookieSigner;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class BotTrustCookieSubscriberTest extends TestCase
{
    private const string SECRET = 'test-secret-for-unit-tests';

    private const string USER_ID = 'msp|bot-trust-unit';

    private const int DAY_MS = 24 * 60 * 60 * 1000;

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
        $signer = new BotTrustCookieSigner(self::SECRET);

        self::assertSame(self::GOLDEN_COOKIE, $signer->buildAt(self::GOLDEN_UID, self::GOLDEN_IAT_MS));
    }

    public function testBuildRoundTripsThroughParse(): void
    {
        $signer = new BotTrustCookieSigner(self::SECRET);
        $uid = BotTrustCookieSigner::uidFor('jan@example.com');

        $before = (int) floor(microtime(true) * 1000);
        $cookie = $signer->build($uid);

        // Issued in real time - the sidecar judges it on its own clock
        $issuedAt = $signer->parseIssuedAt($cookie, $uid);
        self::assertNotNull($issuedAt);
        self::assertGreaterThanOrEqual($before, $issuedAt);
        self::assertLessThanOrEqual((int) ceil(microtime(true) * 1000), $issuedAt);
        self::assertNull($signer->parseIssuedAt($cookie, 'someone-else'));
        self::assertNull($signer->parseIssuedAt('garbage', $uid));
        self::assertNull($signer->parseIssuedAt($cookie . 'x', $uid));
    }

    public function testRevalidationCutoffIsMidnightUtcOfNovember17(): void
    {
        self::assertSame(
            (int) new DateTimeImmutable('2026-11-17T00:00:00Z')->format('Uv'),
            BotTrustCookieSigner::TRUST_REVALIDATE_ISSUED_BEFORE,
        );
    }

    public function testNeedsRefreshByAgeAndByTheCutoff(): void
    {
        $cutoff = BotTrustCookieSigner::TRUST_REVALIDATE_ISSUED_BEFORE;
        $refreshAfter = BotTrustCookieSigner::REFRESH_AFTER_DAYS * self::DAY_MS;

        // Issued at or after the cutoff: fresh until REFRESH_AFTER_DAYS old
        self::assertFalse(BotTrustCookieSigner::needsRefresh($cutoff, $cutoff + 1000));
        self::assertFalse(BotTrustCookieSigner::needsRefresh($cutoff + 1, $cutoff + 1 + $refreshAfter - 1));
        self::assertTrue(BotTrustCookieSigner::needsRefresh($cutoff + 1, $cutoff + 1 + $refreshAfter));

        // Issued before the cutoff: always revalidated, however young
        self::assertTrue(BotTrustCookieSigner::needsRefresh($cutoff - 1, $cutoff));
        self::assertTrue(BotTrustCookieSigner::needsRefresh($cutoff - 1, $cutoff - 1));
    }

    public function testMintsCookieForEligibleAccount(): void
    {
        $event = $this->dispatchResponse(user: $this->account(), eligibility: $this->eligibility(true, 1));

        $cookie = $this->onlyCookie($event);
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('/', $cookie->getPath());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertFalse($cookie->isCleared());

        // The browser keeps it for LIFETIME_DAYS, in real time
        $expectedExpiry = (int) floor(microtime(true)) + BotTrustCookieSigner::LIFETIME_DAYS * 24 * 60 * 60;
        self::assertEqualsWithDelta($expectedExpiry, $cookie->getExpiresTime(), 5);

        $signer = new BotTrustCookieSigner(self::SECRET);
        self::assertNotNull($signer->parseIssuedAt((string) $cookie->getValue(), $this->uid()));
    }

    public function testIneligibleAccountWithoutCookieGetsNothing(): void
    {
        $event = $this->dispatchResponse(user: $this->account(), eligibility: $this->eligibility(false, 1));

        self::assertSame([], $event->getResponse()->headers->getCookies());
    }

    public function testIneligibleAccountsCookieIsExpired(): void
    {
        $signer = new BotTrustCookieSigner(self::SECRET);
        $stale = $signer->buildAt($this->uid(), $this->freshIssuedAtMs() - (BotTrustCookieSigner::REFRESH_AFTER_DAYS + 1) * self::DAY_MS);

        $event = $this->dispatchResponse(
            user: $this->account(),
            existingCookie: $stale,
            eligibility: $this->eligibility(false, 1),
            secure: true,
        );

        $this->assertExpiredCookie($this->onlyCookie($event), secure: true);
    }

    public function testIneligibleAccountsInvalidCookieIsExpiredToo(): void
    {
        $event = $this->dispatchResponse(
            user: $this->account(),
            existingCookie: 'garbage',
            eligibility: $this->eligibility(false, 1),
        );

        $this->assertExpiredCookie($this->onlyCookie($event), secure: false);
    }

    public function testCookieIssuedBeforeTheCutoffIsRevalidatedAndRemintedForEligibleAccount(): void
    {
        $signer = new BotTrustCookieSigner(self::SECRET);
        // Valid, this account's, and younger than REFRESH_AFTER_DAYS on either side of the cutoff date - only the
        // cutoff makes it due
        $preCutoff = $signer->buildAt($this->uid(), BotTrustCookieSigner::TRUST_REVALIDATE_ISSUED_BEFORE - 1);

        $event = $this->dispatchResponse(
            user: $this->account(),
            existingCookie: $preCutoff,
            eligibility: $this->eligibility(true, 1),
        );

        $cookie = $this->onlyCookie($event);
        self::assertFalse($cookie->isCleared());
        self::assertNotSame($preCutoff, $cookie->getValue());
        self::assertNotNull($signer->parseIssuedAt((string) $cookie->getValue(), $this->uid()));
    }

    public function testCookieIssuedBeforeTheCutoffIsExpiredForIneligibleAccount(): void
    {
        $signer = new BotTrustCookieSigner(self::SECRET);
        $preCutoff = $signer->buildAt($this->uid(), BotTrustCookieSigner::TRUST_REVALIDATE_ISSUED_BEFORE - 1);

        $event = $this->dispatchResponse(
            user: $this->account(),
            existingCookie: $preCutoff,
            eligibility: $this->eligibility(false, 1),
        );

        $this->assertExpiredCookie($this->onlyCookie($event), secure: false);
    }

    public function testFreshCookieIsNotRemintedAndAsksNoQuery(): void
    {
        $signer = new BotTrustCookieSigner(self::SECRET);
        $fresh = $signer->buildAt($this->uid(), $this->freshIssuedAtMs());

        $event = $this->dispatchResponse(
            user: $this->account(),
            existingCookie: $fresh,
            eligibility: $this->eligibility(true, 0),
        );

        self::assertSame([], $event->getResponse()->headers->getCookies());
    }

    public function testStaleCookieIsRemintedForEligibleAccount(): void
    {
        $signer = new BotTrustCookieSigner(self::SECRET);
        $stale = $signer->buildAt($this->uid(), $this->freshIssuedAtMs() - (BotTrustCookieSigner::REFRESH_AFTER_DAYS + 1) * self::DAY_MS);

        $event = $this->dispatchResponse(
            user: $this->account(),
            existingCookie: $stale,
            eligibility: $this->eligibility(true, 1),
        );

        self::assertFalse($this->onlyCookie($event)->isCleared());
    }

    public function testForeignAccountCookieIsRemintedForEligibleAccount(): void
    {
        $signer = new BotTrustCookieSigner(self::SECRET);
        $foreign = $signer->buildAt(BotTrustCookieSigner::uidFor('someone-else'), $this->freshIssuedAtMs());

        $event = $this->dispatchResponse(
            user: $this->account(),
            existingCookie: $foreign,
            eligibility: $this->eligibility(true, 1),
        );

        $cookie = $this->onlyCookie($event);
        self::assertNotNull($signer->parseIssuedAt((string) $cookie->getValue(), $this->uid()));
    }

    public function testAnonymousResponseGetsNoCookie(): void
    {
        $event = $this->dispatchResponse(user: null, eligibility: $this->eligibility(true, 0));

        self::assertSame([], $event->getResponse()->headers->getCookies());
    }

    public function testNonAccountUsersAreIgnored(): void
    {
        // API users (PatUser, OAuth2User) are not a UserAccount - they authenticate every call with their token
        $user = new InMemoryUser('api-client', 'irrelevant', ['ROLE_USER']);

        $event = $this->dispatchResponse(user: $user, eligibility: $this->eligibility(true, 0));

        self::assertSame([], $event->getResponse()->headers->getCookies());
    }

    public function testDisabledEntirelyWithoutSecret(): void
    {
        $event = $this->dispatchResponse(user: $this->account(), eligibility: $this->eligibility(true, 0), secret: '');

        self::assertSame([], $event->getResponse()->headers->getCookies());
    }

    public function testSubRequestsAreIgnored(): void
    {
        $event = $this->dispatchResponse(
            user: $this->account(),
            eligibility: $this->eligibility(true, 0),
            requestType: HttpKernelInterface::SUB_REQUEST,
        );

        self::assertSame([], $event->getResponse()->headers->getCookies());
    }

    public function testLogoutExpiresTheCookie(): void
    {
        $request = Request::create('https://myspeedpuzzling.com/logout');
        $request->cookies->set(BotTrustCookieSigner::COOKIE_NAME, 'whatever');
        $event = new LogoutEvent($request, null);
        $event->setResponse(new RedirectResponse('/'));

        $this->subscriber($this->eligibility(true, 0))->onLogout($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        $this->assertExpiredCookie($cookies[0], secure: true);
    }

    public function testLogoutWithoutResponseIsLeftAlone(): void
    {
        $event = new LogoutEvent(Request::create('/logout'), null);

        $this->subscriber($this->eligibility(true, 0))->onLogout($event);

        self::assertNull($event->getResponse());
    }

    private function assertExpiredCookie(Cookie $cookie, bool $secure): void
    {
        self::assertSame(BotTrustCookieSigner::COOKIE_NAME, $cookie->getName());
        self::assertTrue($cookie->isCleared(), 'The cookie must carry an expiry in the past');
        self::assertLessThan(TestClock::now()->getTimestamp(), $cookie->getExpiresTime());
        self::assertSame('/', $cookie->getPath());
        self::assertNull($cookie->getDomain());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame($secure, $cookie->isSecure());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
    }

    private function onlyCookie(ResponseEvent $event): Cookie
    {
        $cookies = $event->getResponse()->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame(BotTrustCookieSigner::COOKIE_NAME, $cookies[0]->getName());

        return $cookies[0];
    }

    /**
     * An issued-at that is "fresh" whatever the real date: never before the revalidation cutoff (a cookie issued
     * before it is always due, so a plain "now" is not fresh until 2026-11-17), and never older than now.
     */
    private function freshIssuedAtMs(): int
    {
        return max((int) floor(microtime(true) * 1000), BotTrustCookieSigner::TRUST_REVALIDATE_ISSUED_BEFORE);
    }

    private function account(): UserAccount
    {
        return new UserAccount(Uuid::uuid7(), self::USER_ID, 'bot-trust@example.com', TestClock::now());
    }

    private function uid(): string
    {
        return BotTrustCookieSigner::uidFor(self::USER_ID);
    }

    private function eligibility(bool $eligible, int $expectedQueries): GetBotTrustEligibility
    {
        $eligibility = $this->createMock(GetBotTrustEligibility::class);
        $eligibility->expects(self::exactly($expectedQueries))
            ->method('isEligible')
            ->with(self::USER_ID)
            ->willReturn($eligible);

        return $eligibility;
    }

    private function subscriber(GetBotTrustEligibility $eligibility, string $secret = self::SECRET, null|UserInterface $user = null): BotTrustCookieSubscriber
    {
        $tokenStorage = new TokenStorage();

        if ($user !== null) {
            $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        }

        return new BotTrustCookieSubscriber(new BotTrustCookieSigner($secret), $tokenStorage, $eligibility);
    }

    private function dispatchResponse(
        null|UserInterface $user,
        GetBotTrustEligibility $eligibility,
        null|string $existingCookie = null,
        string $secret = self::SECRET,
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
        bool $secure = false,
    ): ResponseEvent {
        $request = Request::create(($secure ? 'https' : 'http') . '://myspeedpuzzling.com/muj-profil');
        if ($existingCookie !== null) {
            $request->cookies->set(BotTrustCookieSigner::COOKIE_NAME, $existingCookie);
        }

        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            $requestType,
            new Response(),
        );

        $this->subscriber($eligibility, $secret, $user)->onKernelResponse($event);

        return $event;
    }
}
