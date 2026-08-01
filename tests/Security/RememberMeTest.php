<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\PredictableTrickleVerifier;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\RememberMe\RememberMeDetails;

/**
 * Always-on sliding 30-day remember-me on the main firewall (no checkbox).
 *
 * Several of these are regression guards for the interplay that kept
 * remember-me switched off until now: the window-era Auth0 authenticator fails
 * on every single request, and Symfony's stock RememberMeListener deletes the
 * cookie on every login failure. See MigrationWindowRememberMeListener.
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

    protected function setUp(): void
    {
        PredictableTrickleVerifier::reset();
    }

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
     * in" lasts, because only one of them slides while the visitor is active.
     *
     * The session cookie is re-sent with a fresh Max-Age on every request, so an
     * active visitor is never signed out. The remember-me cookie is NOT renewed
     * during that time - RememberMeAuthenticator::supports() declines whenever a
     * token is already present, so the handler only re-issues on the one path
     * that consumes it. If the session lifetime were the shorter of the two,
     * someone active for months and then idle would fall back to it rather than
     * to the advertised 30 days.
     */
    public function testSessionLifetimeMatchesTheRememberMeLifetime(): void
    {
        $browser = self::createClient();

        $sessionOptions = $browser->getContainer()->getParameter('session.storage.options');

        self::assertSame(
            self::LIFETIME,
            $sessionOptions['gc_maxlifetime'],
            'Session gc_maxlifetime and remember_me lifetime must stay in step - see config/packages/framework.php',
        );
    }

    /**
     * The regression guard for the bug that blocked this feature: on every
     * request after login the Auth0 authenticator fails (the session holds a
     * native token, not Auth0 credentials), and core's listener would clear the
     * cookie it had just issued - remember-me would never outlive one page view.
     */
    public function testRememberMeCookieSurvivesLaterRequests(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|remember2', 'remember.two');

        $this->submitLogin($browser, $email, self::PASSWORD);
        self::assertNotNull($browser->getCookieJar()->get(self::COOKIE_NAME));

        $browser->request('GET', '/en/puzzle');
        self::assertResponseIsSuccessful();

        // Nothing may touch the cookie on an ordinary authenticated page view
        self::assertNull($this->responseCookie($browser));
        self::assertNotNull(
            $browser->getCookieJar()->get(self::COOKIE_NAME),
            'The Auth0 authenticator failing on a native session must not delete the remember-me cookie',
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

        // #164: the Auth0 authenticator fails here too. A deletion cookie would
        // both leak a Set-Cookie onto every anonymous page and make
        // AnonymousCacheHeadersSubscriber bail out of shared caching.
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

        // A real failed sign-in must still drop the cookie - only the Auth0
        // authenticator's bookkeeping failures are exempt
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
     * The remember-me handler is pinned to UserAccountProvider by
     * RememberMeMigrationWindowPass. On the firewall's chain provider this case
     * would fall through to the Auth0 provider, whose loadUserByIdentifier()
     * json_decodes with JSON_THROW_ON_ERROR - an uncaught JsonException, i.e. a
     * 500 on every page for up to 30 days.
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

    public function testLegacyAuth0SessionNeverGetsARememberMeCookie(): void
    {
        $browser = self::createClient();

        TestingLogin::asAuth0Player($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/puzzle');
        self::assertResponseIsSuccessful();

        // The Auth0 authenticator issues no RememberMeBadge, so legacy sessions
        // stay bound to their (shorter-lived) session cookie
        self::assertNull($browser->getCookieJar()->get(self::COOKIE_NAME));
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
