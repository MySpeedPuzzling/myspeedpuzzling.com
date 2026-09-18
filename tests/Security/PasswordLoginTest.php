<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Functional coverage of the password sign-in on the main firewall (issue #147):
 * LoginFormAuthenticator, the imported-bcrypt -> argon2id rehash, the entry
 * point's ?return= funnel, anti-enumeration, CSRF and throttling. Started life
 * as the window-A dual-wiring test of the Auth0 migration.
 *
 * Seeded emails are randomized per run: the login rate limiter's cache is not
 * rolled back between tests or runs, so a reused email would accumulate
 * failed-attempt budget across runs and turn tests flaky.
 */
final class PasswordLoginTest extends WebTestCase
{
    private const string PASSWORD = 'password-login-test-password';

    public function testNativeLoginVerifiesBcryptHashRehashesToArgon2idAndKeepsSession(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'auth0|pwlogin1', 'pwlogin.one', bcryptHashOf: self::PASSWORD);

        $this->submitLogin($browser, $email, self::PASSWORD);

        self::assertResponseRedirects('/en/my-profile');

        // migrate_from verified the imported bcrypt hash and the PasswordUpgradeBadge
        // re-hashed it to argon2id through the explicit upgrader - persisted immediately
        $password = $this->reloadAccountPassword($browser, 'auth0|pwlogin1');
        self::assertNotNull($password);
        self::assertStringStartsWith('$argon2id$', $password);
        self::assertTrue(password_verify(self::PASSWORD, $password));

        // The session survives navigation: the provider refreshes the UserAccount
        $browser->request('GET', '/en/puzzle');
        self::assertResponseIsSuccessful();

        $token = $browser->getContainer()->get(TokenStorageInterface::class)->getToken();
        self::assertNotNull($token);
        self::assertInstanceOf(UserAccount::class, $token->getUser());
        self::assertSame('auth0|pwlogin1', $token->getUserIdentifier());

        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
    }

    public function testEntryPointFunnelReturnsToOriginallyRequestedPage(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|pwlogin2', 'pwlogin.two', argon2idHashOf: self::PASSWORD);

        // Anonymous hit on a protected page: LoginEntryPoint puts the destination
        // in the URL. Nothing is written server-side - no session, no cookie.
        $browser->request('GET', '/admin/referrals');
        self::assertResponseRedirects('/login?return=/admin/referrals');
        self::assertSame([], $browser->getResponse()->headers->getCookies());

        // The login page echoes it back in a hidden field; the authenticator
        // validates it and redirects there
        $this->submitLogin($browser, $email, self::PASSWORD, returnUrl: '/admin/referrals');

        self::assertResponseRedirects('/admin/referrals');
    }

    public function testEntryPointRefusesAnOffSiteDestination(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'msp|pwlogin12', 'pwlogin.twelve', argon2idHashOf: self::PASSWORD);

        // A forged hidden field must not become a post-login redirect - the most
        // valuable open redirect there is, since the victim just typed a password
        $this->submitLogin($browser, $email, self::PASSWORD, returnUrl: 'https://evil.example.com/phish');

        self::assertResponseRedirects('/en/my-profile');
    }

    public function testWrongPasswordAndUnknownEmailFailIdentically(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'auth0|pwlogin3', 'pwlogin.three', bcryptHashOf: self::PASSWORD);

        $this->submitLogin($browser, $email, 'wrong-password');
        $wrongPasswordLocation = (string) $browser->getResponse()->headers->get('Location');
        $wrongPasswordStatus = $browser->getResponse()->getStatusCode();

        $this->submitLogin($browser, 'nobody@example.com', 'wrong-password');
        $unknownEmailLocation = (string) $browser->getResponse()->headers->get('Location');
        $unknownEmailStatus = $browser->getResponse()->getStatusCode();

        // Anti-enumeration: unknown account and wrong password are indistinguishable
        self::assertSame('/login', $wrongPasswordLocation);
        self::assertSame($wrongPasswordLocation, $unknownEmailLocation);
        self::assertSame($wrongPasswordStatus, $unknownEmailStatus);

        $browser->request('GET', '/en/puzzle');
        self::assertNull($browser->getContainer()->get(TokenStorageInterface::class)->getToken());
    }

    public function testSuccessfulNativeLoginWritesLastLoginAt(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'auth0|pwlogin11', 'pwlogin.eleven', bcryptHashOf: self::PASSWORD);

        $this->submitLogin($browser, $email, self::PASSWORD);

        self::assertResponseRedirects('/en/my-profile');

        // AuthenticationAuditSubscriber stamps the login
        $container = $browser->getContainer();
        $container->get(EntityManagerInterface::class)->clear();

        $userAccount = $container->get(UserAccountRepository::class)->findByUserId('auth0|pwlogin11');
        self::assertNotNull($userAccount);
        self::assertNotNull($userAccount->lastLoginAt);
    }

    public function testAnonymousPageStaysSharedCacheable(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle');
        self::assertResponseIsSuccessful();

        // No authenticator may start a session or set a cookie for visitors
        // (#164 anonymous-cacheability constraint)
        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('s-maxage=60', $cacheControl);
        self::assertSame([], $browser->getResponse()->headers->getCookies());
    }

    public function testLoginPostWithoutOriginInfoFailsCsrf(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'auth0|pwlogin9', 'pwlogin.nine', bcryptHashOf: self::PASSWORD);

        // No Origin header and no double-submit cookie: stateless CSRF must reject
        $browser->request('POST', '/login', [
            'email' => $email,
            'password' => self::PASSWORD,
            '_csrf_token' => 'csrf-token',
        ]);

        self::assertResponseRedirects('/login');

        $password = $this->reloadAccountPassword($browser, 'auth0|pwlogin9');
        self::assertNotNull($password);
        self::assertStringStartsWith('$2', $password);
    }

    public function testRepeatedFailuresThrottleTheAccountEvenWithTheCorrectPassword(): void
    {
        $browser = self::createClient();
        $email = $this->seedAccount($browser, 'auth0|pwlogin10', 'pwlogin.ten', bcryptHashOf: self::PASSWORD);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->submitLogin($browser, $email, 'wrong-password');
            self::assertResponseRedirects('/login');
        }

        $this->submitLogin($browser, $email, self::PASSWORD);

        self::assertResponseRedirects('/login');

        $error = $browser->getRequest()->getSession()->get(SecurityRequestAttributes::AUTHENTICATION_ERROR);
        self::assertInstanceOf(TooManyLoginAttemptsAuthenticationException::class, $error);

        // Throttled before the password was ever verified - no rehash happened
        $password = $this->reloadAccountPassword($browser, 'auth0|pwlogin10');
        self::assertNotNull($password);
        self::assertStringStartsWith('$2', $password);
    }

    private function submitLogin(KernelBrowser $browser, string $email, string $password, null|string $returnUrl = null): void
    {
        $parameters = [
            'email' => $email,
            'password' => $password,
            '_csrf_token' => 'csrf-token',
        ];

        if ($returnUrl !== null) {
            $parameters['return'] = $returnUrl;
        }

        $browser->request('POST', '/login', $parameters, [], [
            // Stateless CSRF ('authenticate' token id) validates same-origin requests
            // via the Origin header - BrowserKit does not send one on its own
            'HTTP_ORIGIN' => 'http://localhost',
        ]);
    }

    /**
     * Returns the randomized email.
     */
    private function seedAccount(
        KernelBrowser $browser,
        string $userId,
        string $emailPrefix,
        null|string $bcryptHashOf = null,
        null|string $argon2idHashOf = null,
    ): string {
        $email = sprintf('%s+%s@example.com', $emailPrefix, bin2hex(random_bytes(4)));
        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());

        if (str_starts_with($userId, 'auth0|')) {
            $userAccount->applyAuth0Import(
                $email,
                $bcryptHashOf !== null ? password_hash($bcryptHashOf, PASSWORD_BCRYPT, ['cost' => 4]) : null,
                true,
                new DateTimeImmutable(),
            );
        } elseif ($argon2idHashOf !== null) {
            $userAccount->changePassword(password_hash($argon2idHashOf, PASSWORD_ARGON2ID));
        }

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($userAccount);
        $entityManager->flush();

        return $email;
    }

    private function reloadAccountPassword(KernelBrowser $browser, string $userId): null|string
    {
        $container = $browser->getContainer();
        $container->get(EntityManagerInterface::class)->clear();

        $userAccount = $container->get(UserAccountRepository::class)->findByUserId($userId);
        self::assertNotNull($userAccount);

        return $userAccount->password;
    }
}
