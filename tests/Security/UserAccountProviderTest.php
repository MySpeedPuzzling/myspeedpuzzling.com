<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Security\UserAccountProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class UserAccountProviderTest extends KernelTestCase
{
    private UserAccountProvider $provider;
    private UserAccountRepository $userAccountRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->provider = $container->get(UserAccountProvider::class);
        $this->userAccountRepository = $container->get(UserAccountRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testLoadUserByIdentifierResolvesTheUserIdString(): void
    {
        $this->createUserAccount('auth0|provider1', 'provider.one@example.com');

        $userAccount = $this->provider->loadUserByIdentifier('auth0|provider1');

        self::assertSame('auth0|provider1', $userAccount->getUserIdentifier());
        self::assertSame('provider.one@example.com', $userAccount->email);
    }

    public function testLoadUserByIdentifierThrowsForUnknownUserId(): void
    {
        $this->expectException(UserNotFoundException::class);

        $this->provider->loadUserByIdentifier('msp|does-not-exist');
    }

    public function testLoadUserByIdentifierDoesNotResolveEmails(): void
    {
        // The provider identifier is the user_id string; email login lives in the
        // authenticator's UserBadge loader only (badge identifier != user identifier)
        $this->createUserAccount('auth0|provider2', 'provider.two@example.com');

        $this->expectException(UserNotFoundException::class);

        $this->provider->loadUserByIdentifier('provider.two@example.com');
    }

    public function testRefreshUserReloadsFromDatabase(): void
    {
        $userAccount = $this->createUserAccount('msp|provider3', 'provider.three@example.com');
        $this->entityManager->clear();

        $refreshed = $this->provider->refreshUser($userAccount);

        self::assertSame('msp|provider3', $refreshed->getUserIdentifier());
        self::assertNotSame($userAccount, $refreshed);
    }

    public function testRefreshUserRejectsForeignUserClasses(): void
    {
        // The window-A chain provider relies on this exception to pass Auth0
        // session users on to the Auth0 provider
        $this->expectException(UnsupportedUserException::class);

        $this->provider->refreshUser(new InMemoryUser('someone', null));
    }

    public function testSupportsClassOnlyUserAccount(): void
    {
        self::assertTrue($this->provider->supportsClass(UserAccount::class));
        self::assertFalse($this->provider->supportsClass(InMemoryUser::class));
    }

    public function testUpgradePasswordPersistsImmediately(): void
    {
        $userAccount = $this->createUserAccount('auth0|provider4', 'provider.four@example.com');

        $this->provider->upgradePassword($userAccount, '$argon2id$fresh-hash');

        // The upgrade happens inside the security listener where no Messenger
        // transaction exists - it must be flushed already (D10)
        $this->entityManager->clear();
        $reloaded = $this->userAccountRepository->findByUserId('auth0|provider4');

        self::assertNotNull($reloaded);
        self::assertSame('$argon2id$fresh-hash', $reloaded->password);
    }

    public function testUpgradePasswordIgnoresForeignUserClasses(): void
    {
        $this->provider->upgradePassword(new InMemoryUser('someone', 'old-password'), 'new-hash');

        $this->expectNotToPerformAssertions();
    }

    private function createUserAccount(string $userId, string $email): UserAccount
    {
        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());

        $this->entityManager->persist($userAccount);
        $this->entityManager->flush();

        return $userAccount;
    }
}
