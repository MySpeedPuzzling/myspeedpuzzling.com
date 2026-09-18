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
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;

/**
 * Emails are randomized per run: the login rate limiter's cache is not rolled
 * back by DAMA, so a reused email would accumulate attempt budget across runs.
 */
final class LoginFormAuthenticatorTest extends KernelTestCase
{
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
        $account = $this->createAccount('auth0|authr1', 'authr.one', bcryptHash: '$2b$04$abcdefghijklmnopqrstuv');

        $passport = $authenticator->authenticate($this->loginRequest($account->email, 'whatever'));

        self::assertTrue($passport->hasBadge(PasswordCredentials::class));
        self::assertTrue($passport->hasBadge(CsrfTokenBadge::class));
        self::assertTrue($passport->hasBadge(RememberMeBadge::class));
        self::assertTrue($passport->hasBadge(PasswordUpgradeBadge::class));
    }

    public function testBadgeIdentifierIsEmailButUserIdentifierStaysUserId(): void
    {
        $authenticator = $this->authenticator();
        $account = $this->createAccount('auth0|authr2', 'authr.two', bcryptHash: '$2b$04$abcdefghijklmnopqrstuv');

        $passport = $authenticator->authenticate($this->loginRequest($account->email, 'whatever'));

        $userBadge = $passport->getBadge(UserBadge::class);
        self::assertNotNull($userBadge);
        self::assertSame($account->email, $userBadge->getUserIdentifier());
        self::assertSame('auth0|authr2', $passport->getUser()->getUserIdentifier());
    }

    /**
     * Every account verifies against its local hash, full stop - an account without
     * one (imported from Auth0 without a hash, or a social-only account) fails the
     * local password check and is pointed at the sign-in link and reset doors. No
     * third party is consulted any more (the Auth0 trickle fallback is gone).
     */
    public function testAccountWithoutALocalHashStillGetsPasswordCredentials(): void
    {
        $authenticator = $this->authenticator();
        $accountsWithoutHash = [
            $this->createAccount('auth0|authr3', 'authr.three', bcryptHash: null, legacyAuth0: true),
            $this->createAccount('msp|authr4', 'authr.four', bcryptHash: null),
        ];

        foreach ($accountsWithoutHash as $account) {
            $passport = $authenticator->authenticate($this->loginRequest($account->email, 'whatever'));

            self::assertTrue($passport->hasBadge(PasswordCredentials::class));
        }
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

        $passport = $authenticator->authenticate(
            $this->loginRequest(sprintf('nobody+%s@example.com', bin2hex(random_bytes(4))), 'whatever'),
        );

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
        string $emailPrefix,
        null|string $bcryptHash,
        bool $legacyAuth0 = false,
    ): UserAccount {
        $email = sprintf('%s+%s@example.com', $emailPrefix, bin2hex(random_bytes(4)));
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
