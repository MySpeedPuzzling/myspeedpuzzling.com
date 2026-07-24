<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use Auth0\Symfony\Models\User;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Security\LoginFormAuthenticator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\PredictableTrickleVerifier;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Functional coverage of the window-A dual wiring (Stage A -> Stage B of the
 * Auth0 migration, issue #147): LoginFormAuthenticator and the Auth0 session
 * authenticator run side by side on the main firewall, refreshed through the
 * chain user provider, with the Auth0 entry point still in charge.
 */
final class WindowADualWiringTest extends WebTestCase
{
    private const string PASSWORD = 'window-a-test-password';

    protected function setUp(): void
    {
        PredictableTrickleVerifier::reset();
    }

    public function testNativeLoginVerifiesBcryptHashRehashesToArgon2idAndKeepsSession(): void
    {
        $browser = self::createClient();
        $this->seedAccount($browser, 'auth0|windowa1', 'windowa.one@example.com', bcryptHashOf: self::PASSWORD);

        $this->submitLogin($browser, 'windowa.one@example.com', self::PASSWORD);

        self::assertResponseRedirects('/en/my-profile');

        // migrate_from verified the imported bcrypt hash and the PasswordUpgradeBadge
        // re-hashed it to argon2id through the chain provider - persisted immediately
        $password = $this->reloadAccountPassword($browser, 'auth0|windowa1');
        self::assertNotNull($password);
        self::assertStringStartsWith('$argon2id$', $password);
        self::assertTrue(password_verify(self::PASSWORD, $password));

        // The session survives navigation: the chain provider must refresh the
        // native UserAccount (the Auth0 provider would explode on this identifier)
        $browser->request('GET', '/en/puzzle');
        self::assertResponseIsSuccessful();

        $token = $browser->getContainer()->get(TokenStorageInterface::class)->getToken();
        self::assertNotNull($token);
        self::assertInstanceOf(UserAccount::class, $token->getUser());
        self::assertSame('auth0|windowa1', $token->getUserIdentifier());

        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
    }

    public function testEntryPointFunnelReturnsToOriginallyRequestedPage(): void
    {
        $browser = self::createClient();
        $this->seedAccount($browser, 'msp|windowa2', 'windowa.two@example.com', argon2idHashOf: self::PASSWORD);

        // Anonymous hit on a protected page: still the Auth0 entry point (window A),
        // and the ExceptionListener records the target path in the session
        $browser->request('GET', '/admin/affiliates');
        self::assertResponseRedirects('/login');

        $this->submitLogin($browser, 'windowa.two@example.com', self::PASSWORD);

        self::assertResponseRedirects('http://localhost/admin/affiliates');
    }

    public function testWrongPasswordAndUnknownEmailFailIdentically(): void
    {
        $browser = self::createClient();
        $this->seedAccount($browser, 'auth0|windowa3', 'windowa.three@example.com', bcryptHashOf: self::PASSWORD);

        $this->submitLogin($browser, 'windowa.three@example.com', 'wrong-password');
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

    public function testAuth0SessionStillAuthenticatesThroughTheChainProvider(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/puzzle');
        self::assertResponseIsSuccessful();

        $token = $browser->getContainer()->get(TokenStorageInterface::class)->getToken();
        self::assertNotNull($token);
        self::assertInstanceOf(User::class, $token->getUser());

        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
    }

    public function testAnonymousPageStaysSharedCacheableWithDualWiring(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle');
        self::assertResponseIsSuccessful();

        // Neither authenticator may start a session or set a cookie for visitors
        // (#164 anonymous-cacheability constraint)
        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('s-maxage=60', $cacheControl);
        self::assertSame([], $browser->getResponse()->headers->getCookies());
    }

    public function testTrickleLoginAdoptsThePasswordLocallyAndConsultsAuth0Once(): void
    {
        $browser = self::createClient();
        $this->seedAccount($browser, 'auth0|windowa4', 'windowa.four@example.com');

        $this->submitLogin($browser, 'windowa.four@example.com', PredictableTrickleVerifier::CORRECT_PASSWORD);

        self::assertResponseRedirects('/en/my-profile');
        self::assertSame(['windowa.four@example.com'], PredictableTrickleVerifier::calls());

        $password = $this->reloadAccountPassword($browser, 'auth0|windowa4');
        self::assertNotNull($password);
        self::assertStringStartsWith('$argon2id$', $password);
        self::assertTrue(password_verify(PredictableTrickleVerifier::CORRECT_PASSWORD, $password));

        // Second login now takes the local-hash branch - Auth0 consulted at most once
        $this->submitLogin($browser, 'windowa.four@example.com', PredictableTrickleVerifier::CORRECT_PASSWORD);

        self::assertResponseRedirects('/en/my-profile');
        self::assertCount(1, PredictableTrickleVerifier::calls());
    }

    public function testTrickleRejectionFailsTheLoginAndAdoptsNothing(): void
    {
        $browser = self::createClient();
        $this->seedAccount($browser, 'auth0|windowa5', 'windowa.five@example.com');

        $this->submitLogin($browser, 'windowa.five@example.com', 'not-the-right-password');

        self::assertResponseRedirects('/login');
        self::assertCount(1, PredictableTrickleVerifier::calls());
        self::assertNull($this->reloadAccountPassword($browser, 'auth0|windowa5'));
    }

    public function testTrickleLeakedPasswordFailsWithDistinctResetMessage(): void
    {
        $browser = self::createClient();
        $this->seedAccount($browser, 'auth0|windowa6', 'windowa.six@example.com');

        $this->submitLogin($browser, 'windowa.six@example.com', PredictableTrickleVerifier::LEAKED_PASSWORD);

        self::assertResponseRedirects('/login');
        self::assertNull($this->reloadAccountPassword($browser, 'auth0|windowa6'));

        $error = $browser->getRequest()->getSession()->get(SecurityRequestAttributes::AUTHENTICATION_ERROR);
        self::assertInstanceOf(CustomUserMessageAuthenticationException::class, $error);
        self::assertSame(LoginFormAuthenticator::ERROR_PASSWORD_LEAKED, $error->getMessageKey());
    }

    public function testTrickleOutageFailsClosedWithoutMarkingThePasswordWrong(): void
    {
        $browser = self::createClient();
        $this->seedAccount($browser, 'auth0|windowa7', 'windowa.seven@example.com');

        $this->submitLogin($browser, 'windowa.seven@example.com', PredictableTrickleVerifier::AUTH0_DOWN_PASSWORD);

        self::assertResponseRedirects('/login');
        self::assertNull($this->reloadAccountPassword($browser, 'auth0|windowa7'));

        $error = $browser->getRequest()->getSession()->get(SecurityRequestAttributes::AUTHENTICATION_ERROR);
        self::assertInstanceOf(CustomUserMessageAuthenticationException::class, $error);
        self::assertSame(LoginFormAuthenticator::ERROR_TEMPORARILY_UNAVAILABLE, $error->getMessageKey());
    }

    public function testAccountWithLocalHashNeverConsultsAuth0(): void
    {
        $browser = self::createClient();
        $this->seedAccount($browser, 'auth0|windowa8', 'windowa.eight@example.com', bcryptHashOf: self::PASSWORD);

        $this->submitLogin($browser, 'windowa.eight@example.com', 'wrong-password');

        self::assertResponseRedirects('/login');
        self::assertSame([], PredictableTrickleVerifier::calls());
    }

    public function testLoginPostWithoutOriginInfoFailsCsrf(): void
    {
        $browser = self::createClient();
        $this->seedAccount($browser, 'auth0|windowa9', 'windowa.nine@example.com', bcryptHashOf: self::PASSWORD);

        // No Origin header and no double-submit cookie: stateless CSRF must reject
        $browser->request('POST', '/login', [
            'email' => 'windowa.nine@example.com',
            'password' => self::PASSWORD,
            '_csrf_token' => 'csrf-token',
        ]);

        self::assertResponseRedirects('/login');

        $password = $this->reloadAccountPassword($browser, 'auth0|windowa9');
        self::assertNotNull($password);
        self::assertStringStartsWith('$2', $password);
    }

    private function submitLogin(KernelBrowser $browser, string $email, string $password): void
    {
        $browser->request('POST', '/login', [
            'email' => $email,
            'password' => $password,
            '_csrf_token' => 'csrf-token',
        ], [], [
            // Stateless CSRF ('authenticate' token id) validates same-origin requests
            // via the Origin header - BrowserKit does not send one on its own
            'HTTP_ORIGIN' => 'http://localhost',
        ]);
    }

    /**
     * No hash argument seeds an imported legacy account whose hash did not make
     * it into the export - the trickle scenario.
     */
    private function seedAccount(
        KernelBrowser $browser,
        string $userId,
        string $email,
        null|string $bcryptHashOf = null,
        null|string $argon2idHashOf = null,
    ): void {
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
