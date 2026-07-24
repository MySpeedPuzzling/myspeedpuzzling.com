<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Security\LoginFormAuthenticator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\PasswordUpgradeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;

final class LoginFormAuthenticatorTest extends KernelTestCase
{
    private null|string $originalTrickleFlag = null;

    protected function setUp(): void
    {
        $flag = $_ENV['AUTH0_TRICKLE_LOGIN_ENABLED'] ?? null;
        $this->originalTrickleFlag = is_string($flag) ? $flag : null;
    }

    protected function tearDown(): void
    {
        if ($this->originalTrickleFlag !== null) {
            $_ENV['AUTH0_TRICKLE_LOGIN_ENABLED'] = $this->originalTrickleFlag;
            $_SERVER['AUTH0_TRICKLE_LOGIN_ENABLED'] = $this->originalTrickleFlag;
        }

        parent::tearDown();
    }

    public function testSupportsOnlyPostToLoginPath(): void
    {
        $authenticator = $this->authenticator();

        self::assertTrue($authenticator->supports(Request::create('/login', 'POST')));
        self::assertFalse($authenticator->supports(Request::create('/login', 'GET')));
        self::assertFalse($authenticator->supports(Request::create('/en/puzzle', 'POST')));
    }

    public function testAccountWithLocalHashGetsPasswordCredentials(): void
    {
        $authenticator = $this->authenticator();
        $this->createAccount('auth0|authr1', 'authr.one@example.com', bcryptHash: '$2b$04$abcdefghijklmnopqrstuv');

        $passport = $authenticator->authenticate($this->loginRequest('authr.one@example.com', 'whatever'));

        self::assertTrue($passport->hasBadge(PasswordCredentials::class));
        self::assertFalse($passport->hasBadge(CustomCredentials::class));
        self::assertTrue($passport->hasBadge(CsrfTokenBadge::class));
        self::assertTrue($passport->hasBadge(RememberMeBadge::class));
        self::assertTrue($passport->hasBadge(PasswordUpgradeBadge::class));
    }

    public function testBadgeIdentifierIsEmailButUserIdentifierStaysUserId(): void
    {
        $authenticator = $this->authenticator();
        $this->createAccount('auth0|authr2', 'authr.two@example.com', bcryptHash: '$2b$04$abcdefghijklmnopqrstuv');

        $passport = $authenticator->authenticate($this->loginRequest('authr.two@example.com', 'whatever'));

        $userBadge = $passport->getBadge(UserBadge::class);
        self::assertNotNull($userBadge);
        self::assertSame('authr.two@example.com', $userBadge->getUserIdentifier());
        self::assertSame('auth0|authr2', $passport->getUser()->getUserIdentifier());
    }

    public function testLegacyAccountWithoutLocalHashGetsTrickleCredentials(): void
    {
        $authenticator = $this->authenticator();
        $this->createAccount('auth0|authr3', 'authr.three@example.com', bcryptHash: null, legacyAuth0: true);

        $passport = $authenticator->authenticate($this->loginRequest('authr.three@example.com', 'whatever'));

        self::assertTrue($passport->hasBadge(CustomCredentials::class));
        self::assertFalse($passport->hasBadge(PasswordCredentials::class));
    }

    public function testNativeAccountWithoutHashNeverTrickles(): void
    {
        $authenticator = $this->authenticator();
        $this->createAccount('msp|authr4', 'authr.four@example.com', bcryptHash: null, legacyAuth0: false);

        $passport = $authenticator->authenticate($this->loginRequest('authr.four@example.com', 'whatever'));

        // A native account with no password (future social-only accounts) must fail
        // the local password check, never consult Auth0
        self::assertTrue($passport->hasBadge(PasswordCredentials::class));
    }

    public function testTrickleFlagOffFallsBackToLocalPasswordCheck(): void
    {
        $_ENV['AUTH0_TRICKLE_LOGIN_ENABLED'] = '0';
        $_SERVER['AUTH0_TRICKLE_LOGIN_ENABLED'] = '0';

        $authenticator = $this->authenticator();
        $this->createAccount('auth0|authr5', 'authr.five@example.com', bcryptHash: null, legacyAuth0: true);

        $passport = $authenticator->authenticate($this->loginRequest('authr.five@example.com', 'whatever'));

        self::assertTrue($passport->hasBadge(PasswordCredentials::class));
        self::assertFalse($passport->hasBadge(CustomCredentials::class));
    }

    public function testEmptyEmailOrPasswordFailsBeforeAnyLookup(): void
    {
        $authenticator = $this->authenticator();

        $this->expectException(BadCredentialsException::class);

        $authenticator->authenticate($this->loginRequest('', 'whatever'));
    }

    public function testUnknownEmailSurfacesAsUserNotFoundOnUserAccess(): void
    {
        $authenticator = $this->authenticator();

        $passport = $authenticator->authenticate($this->loginRequest('nobody@example.com', 'whatever'));

        // Symfony hides this as BadCredentialsException (hide_user_not_found),
        // keeping unknown email indistinguishable from wrong password
        $this->expectException(UserNotFoundException::class);

        $passport->getUser();
    }

    private function authenticator(): LoginFormAuthenticator
    {
        self::bootKernel();

        return self::getContainer()->get(LoginFormAuthenticator::class);
    }

    private function createAccount(
        string $userId,
        string $email,
        null|string $bcryptHash,
        bool $legacyAuth0 = false,
    ): UserAccount {
        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());

        if ($legacyAuth0) {
            $userAccount->applyAuth0Import($email, $bcryptHash, false, new DateTimeImmutable());
        } elseif ($bcryptHash !== null) {
            $userAccount->changePassword($bcryptHash);
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($userAccount);
        $entityManager->flush();

        return $userAccount;
    }

    private function loginRequest(string $email, string $password): Request
    {
        return Request::create('/login', 'POST', [
            'email' => $email,
            'password' => $password,
            '_csrf_token' => 'csrf-token',
        ]);
    }
}
