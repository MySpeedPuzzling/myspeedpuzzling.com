<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\RememberMe\RememberMeDetails;

/**
 * Always-on sliding 30-day remember-me on the main firewall (no checkbox).
 *
 * Several of these started as regression guards for the Auth0 migration window,
 * when the Auth0 authenticator failed on every single request and Symfony's
 * stock RememberMeListener deletes the cookie on every login failure. They stay:
 * any authenticator that fails on plain page views would break them again.
 *
 * Seeded emails are randomized per run - the login rate limiter's cache is not
 * rolled back between tests or runs, so a reused address accumulates
 * failed-attempt budget and turns tests flaky.
 */
final class RememberMeTest extends WebTestCase
{
    private const string PASSWORD = 'remember-me-test-password';

    private const string COOKIE_NAME = 'REMEMBERME';

    private const int LIFETIME = 2592000;

    public function testLoginIssuesARememberMeCookieWithoutAnyCheckbox(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|remember1', 'remember.one');

        // No _remember_me parameter is sent anywhere - always_remember_me carries it
        $this->submitLogin($browser, $email, self::PASSWORD);

        $cookie = $this->responseCookie($browser);
        self::assertNotNull($cookie, 'Every successful sign-in must mint a remember-me cookie');
        self::assertNotSame('', (string) $cookie->getValue());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertSame('/', $cookie->getPath());

        // 30 days out, give or take the second the request took
        self::assertEqualsWithDelta(time() + self::LIFETIME, $cookie->getExpiresTime(), 30);
    }

    /**
     * The session and the remember-me cookie must agree on how long "stay signed
     * in" lasts, because SlidingLoginCookiesSubscriber renews the two together
     * and a visitor is signed out as soon as the shorter of them lapses.
     *
     * Neither cookie renews itself - see config/packages/framework.php. Without
     * that subscriber both expire 30 days after the password was typed, however
     * active the visitor has been.
     */
    public function testSessionLifetimeMatchesTheRememberMeLifetime(): void
    {
        $browser = self::createClient();

        $sessionLifetime = $browser->getContainer()->getParameter('session.storage.options')['gc_maxlifetime'];

        self::assertSame(
            self::LIFETIME,
            $sessionLifetime,
            'Session gc_maxlifetime and remember_me lifetime must stay in step - see config/packages/framework.php',
        );
    }

    /**
     * The regression guard for the bug that once blocked this feature: an
     * authenticator failing on every request after login (the migration-era
     * Auth0 one did) makes core's listener clear the cookie it had just issued -
     * remember-me would never outlive one page view.
     */
    public function testRememberMeCookieSurvivesLaterRequests(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|remember2', 'remember.two');

        $this->submitLogin($browser, $email, self::PASSWORD);
        self::assertNotNull($browser->getCookieJar()->get(self::COOKIE_NAME));

        $browser->request('GET', '/en/puzzle');
        self::assertResponseIsSuccessful();

        // SlidingLoginCookiesSubscriber may RENEW the cookie here (that is its
        // job), but nothing may DELETE it: a deletion cookie carries an empty
        // value and an expiry in the past.
        $onResponse = $this->responseCookie($browser);

        if ($onResponse !== null) {
            self::assertNotSame('', (string) $onResponse->getValue(), 'A renewal must not be a deletion cookie');
            self::assertGreaterThan(time(), $onResponse->getExpiresTime());
        }

        self::assertNotNull(
            $browser->getCookieJar()->get(self::COOKIE_NAME),
            'A later page view must not delete the remember-me cookie',
        );
    }

    public function testExpiredSessionIsRestoredFromTheCookieAndTheCookieSlides(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|remember3', 'remember.three');

        $this->submitLogin($browser, $email, self::PASSWORD);

        $this->dropEverythingButTheRememberMeCookie($browser);

        $browser->request('GET', '/en/puzzle');
        self::assertResponseIsSuccessful();

        $token = $browser->getContainer()->get(TokenStorageInterface::class)->getToken();
        self::assertNotNull($token, 'A visitor with only a remember-me cookie must be signed back in');
        self::assertInstanceOf(UserAccount::class, $token->getUser());
        self::assertSame('msp|remember3', $token->getUserIdentifier());

        // Sliding window: consuming the cookie re-issues it with a fresh 30 days
        // (SignatureRememberMeHandler::processRememberMe), so an active user is
        // never signed out
        $reissued = $this->responseCookie($browser);
        self::assertNotNull($reissued, 'Consuming the cookie must re-issue it');
        self::assertEqualsWithDelta(time() + self::LIFETIME, $reissued->getExpiresTime(), 30);
    }

    public function testAnonymousResponsesCarryNoRememberMeCookieAndStaySharedCacheable(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle');
        self::assertResponseIsSuccessful();

        // #164: a deletion cookie would both leak a Set-Cookie onto every
        // anonymous page and make AnonymousCacheHeadersSubscriber bail out of
        // shared caching.
        self::assertSame([], $browser->getResponse()->headers->getCookies());

        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('s-maxage=60', $cacheControl);
    }

    public function testFailedLoginStillClearsTheCookie(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|remember4', 'remember.four');

        $this->submitLogin($browser, $email, self::PASSWORD);
        self::assertNotNull($browser->getCookieJar()->get(self::COOKIE_NAME));

        // A real failed sign-in drops the cookie (core RememberMeListener)
        $this->submitLogin($browser, $email, 'wrong-password');

        self::assertNull($browser->getCookieJar()->get(self::COOKIE_NAME));
    }

    public function testLogoutClearsTheCookie(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|remember5', 'remember.five');

        $this->submitLogin($browser, $email, self::PASSWORD);
        self::assertNotNull($browser->getCookieJar()->get(self::COOKIE_NAME));

        // The sign-out link in base.html.twig points at /logout, which is Auth0's
        // controller; with no Auth0 credentials it redirects on to /app-logout,
        // the firewall's own logout. Follow the whole chain - that redirect is
        // what makes LogoutEvent fire for a natively signed-in user.
        $browser->followRedirects();
        $browser->request('GET', '/logout');

        self::assertNull($browser->getCookieJar()->get(self::COOKIE_NAME));
    }

    public function testChangingThePasswordInvalidatesExistingCookies(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|remember6', 'remember.six');

        $this->submitLogin($browser, $email, self::PASSWORD);
        $this->dropEverythingButTheRememberMeCookie($browser);

        // signature_properties: a password reset signs every device out
        $this->mutateAccount($browser, 'msp|remember6', static function (UserAccount $account): void {
            $account->changePassword(password_hash('a-completely-new-password', PASSWORD_ARGON2ID));
        });

        $browser->request('GET', '/en/puzzle');
        self::assertResponseIsSuccessful();

        self::assertNull(
            $browser->getContainer()->get(TokenStorageInterface::class)->getToken(),
            'A cookie signed with the old password must no longer authenticate',
        );
    }

    public function testChangingTheEmailInvalidatesExistingCookies(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|remember7', 'remember.seven');

        $this->submitLogin($browser, $email, self::PASSWORD);
        $this->dropEverythingButTheRememberMeCookie($browser);

        $this->mutateAccount($browser, 'msp|remember7', static function (UserAccount $account): void {
            $account->changeEmail(sprintf('moved+%s@example.com', bin2hex(random_bytes(4))));
        });

        $browser->request('GET', '/en/puzzle');
        self::assertResponseIsSuccessful();

        self::assertNull($browser->getContainer()->get(TokenStorageInterface::class)->getToken());
    }

    /**
     * The remember-me handler loads through UserAccountProvider, which answers a
     * missing account with UserNotFoundException - "invalid cookie, stay
     * anonymous". During the migration window the firewall's chain provider fell
     * through to the Auth0 provider here instead, whose JsonException was a 500
     * on every page for up to 30 days.
     */
    public function testDeletedAccountWithAValidCookieDegradesToAnonymous(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|remember8', 'remember.eight');

        $this->submitLogin($browser, $email, self::PASSWORD);
        $this->dropEverythingButTheRememberMeCookie($browser);

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $account = $browser->getContainer()->get(UserAccountRepository::class)->findByUserId('msp|remember8');
        self::assertNotNull($account);
        $entityManager->remove($account);
        $entityManager->flush();

        $browser->request('GET', '/en/puzzle');

        self::assertResponseIsSuccessful();
        self::assertNull($browser->getContainer()->get(TokenStorageInterface::class)->getToken());
    }

    /**
     * FrankenPHP worker mode keeps the kernel and every service alive across
     * requests, so anything the remember-me machinery stashes on a service
     * instead of on the Request would bleed between visitors. disableReboot()
     * reproduces that exactly: one container, three consecutive requests from
     * three different visitors.
     *
     * The handler writes its cookie into the current Request's attributes
     * (AbstractRememberMeHandler::createCookie) and the listener is readonly,
     * so there is nowhere for it to leak - this pins that down.
     */
    public function testCookieDoesNotLeakBetweenVisitorsInWorkerMode(): void
    {
        $browser = self::createClient();
        // Same kernel instance for every request from here on
        $browser->disableReboot();

        $emailOne = $this->seedAccount($browser, 'msp|remember9', 'remember.nine');
        $emailTwo = $this->seedAccount($browser, 'msp|remember10', 'remember.ten');

        $this->submitLogin($browser, $emailOne, self::PASSWORD);
        $cookieOne = $this->responseCookie($browser);
        self::assertNotNull($cookieOne);

        // Visitor 2: brand new, no cookies. Must get neither a token nor a cookie.
        $browser->getCookieJar()->clear();
        $browser->request('GET', '/en/puzzle');

        self::assertResponseIsSuccessful();
        self::assertSame(
            [],
            $browser->getResponse()->headers->getCookies(),
            'A cookie minted for the previous visitor must not reappear on an anonymous response',
        );
        self::assertNull($browser->getContainer()->get(TokenStorageInterface::class)->getToken());

        // Visitor 3: signs in as somebody else and must get their own cookie
        $this->submitLogin($browser, $emailTwo, self::PASSWORD);
        $cookieTwo = $this->responseCookie($browser);

        self::assertNotNull($cookieTwo);
        self::assertNotSame((string) $cookieOne->getValue(), (string) $cookieTwo->getValue());
        self::assertSame(
            'msp|remember10',
            RememberMeDetails::fromRawCookie((string) $cookieTwo->getValue())->getUserIdentifier(),
            'The cookie must carry the identity that just signed in',
        );
        self::assertSame(
            'msp|remember9',
            RememberMeDetails::fromRawCookie((string) $cookieOne->getValue())->getUserIdentifier(),
        );
    }

    /**
     * The regression guard for the bug that made always-on remember-me useless in
     * practice: the cookie authenticated fine, but the resulting RememberMeToken
     * is not "full fledged", so all 125 IsGranted('IS_AUTHENTICATED_FULLY') gates
     * in src/Controller rejected it. The entry point then sent the visitor to
     * /login, and /login - seeing a user in the token storage - sent them straight
     * back to the page that had just rejected them. Opening the app after the
     * session died was an infinite redirect, which reads to a user as "it logged
     * me out again".
     */
    public function testRememberMeRestoredVisitorReachesTheSignedInAreaInsteadOfLooping(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|remember11', 'remember.eleven');

        $this->submitLogin($browser, $email, self::PASSWORD);
        $this->dropEverythingButTheRememberMeCookie($browser);

        $browser->request('GET', '/en/my-profile');

        // This account has no Player row, so my_profile redirects to the homepage.
        // What matters is where it does NOT send them.
        $location = (string) $browser->getResponse()->headers->get('Location');

        self::assertStringNotContainsString(
            '/login',
            $location,
            'A visitor restored from the remember-me cookie must not be bounced to the login page',
        );
        self::assertNotNull(
            $browser->getContainer()->get(TokenStorageInterface::class)->getToken(),
            'The remember-me cookie must still authenticate the request',
        );
    }

    /**
     * Neither cookie renews itself (see config/packages/framework.php), so without
     * SlidingLoginCookiesSubscriber both expire 30 days after the password was
     * typed no matter how active the visitor is. The subscriber re-sends them, at
     * most once a day.
     */
    public function testBothLoginCookiesSlideOnALaterPageViewAndThenThrottle(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|remember12', 'remember.twelve');

        $this->submitLogin($browser, $email, self::PASSWORD);

        // First page view after login: no renewal has been recorded in the session
        // yet, so both cookies are re-sent with a fresh 30-day window. This is also
        // what rescues everyone who was already signed in when this shipped.
        $browser->request('GET', '/en/puzzle');
        self::assertResponseIsSuccessful();

        $rememberMe = $this->responseCookie($browser);
        self::assertNotNull($rememberMe, 'The remember-me cookie must be renewed on a later page view');
        self::assertEqualsWithDelta(time() + self::LIFETIME, $rememberMe->getExpiresTime(), 30);

        $sessionCookie = $this->responseCookieNamed($browser, $this->sessionName($browser));
        self::assertNotNull($sessionCookie, 'The session cookie must be renewed alongside it');

        // Second page view: inside the throttle window, so nothing is re-sent.
        $browser->request('GET', '/en/puzzle');
        self::assertResponseIsSuccessful();

        self::assertNull(
            $this->responseCookie($browser),
            'Renewal must be throttled - one Set-Cookie per visitor per day, not one per request',
        );
    }

    private function sessionName(KernelBrowser $browser): string
    {
        $session = $browser->getContainer()->get('session.factory')->createSession();

        return $session->getName();
    }

    private function responseCookieNamed(KernelBrowser $browser, string $name): null|Cookie
    {
        foreach ($browser->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    private function responseCookie(KernelBrowser $browser): null|Cookie
    {
        foreach ($browser->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === self::COOKIE_NAME) {
                return $cookie;
            }
        }

        return null;
    }

    /**
     * Simulates an expired/lost session while keeping the remember-me cookie -
     * the only situation in which the cookie is actually consumed. Clearing the
     * whole jar and putting one cookie back avoids depending on the session
     * cookie's name, which differs between the mock storage and production.
     */
    private function dropEverythingButTheRememberMeCookie(KernelBrowser $browser): void
    {
        $jar = $browser->getCookieJar();
        $rememberMe = $jar->get(self::COOKIE_NAME);
        self::assertNotNull($rememberMe);

        $jar->clear();
        $jar->set($rememberMe);
    }

    /**
     * @param callable(UserAccount): void $mutate
     */
    private function mutateAccount(KernelBrowser $browser, string $userId, callable $mutate): void
    {
        $container = $browser->getContainer();
        $account = $container->get(UserAccountRepository::class)->findByUserId($userId);
        self::assertNotNull($account);

        $mutate($account);

        $container->get(EntityManagerInterface::class)->flush();
    }

    private function submitLogin(KernelBrowser $browser, string $email, string $password): void
    {
        $browser->request('POST', '/login', [
            'email' => $email,
            'password' => $password,
            '_csrf_token' => 'csrf-token',
        ], [], [
            // Stateless CSRF validates same-origin via Origin; BrowserKit sends none
            'HTTP_ORIGIN' => 'http://localhost',
        ]);
    }

    private function seedAccount(KernelBrowser $browser, string $userId, string $emailPrefix): string
    {
        $email = sprintf('%s+%s@example.com', $emailPrefix, bin2hex(random_bytes(4)));
        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());
        $userAccount->changePassword(password_hash(self::PASSWORD, PASSWORD_ARGON2ID));

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($userAccount);
        $entityManager->flush();

        return $email;
    }
}
