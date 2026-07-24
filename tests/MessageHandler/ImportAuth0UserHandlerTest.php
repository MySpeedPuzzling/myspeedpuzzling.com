<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Message\ImportAuth0User;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ImportAuth0UserHandlerTest extends KernelTestCase
{
    private const string BCRYPT_HASH = '$2b$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy';
    private const string OTHER_BCRYPT_HASH = '$2b$10$hSbdpahyPZuvmH1eqBS9NuBBnbNZKvp4rmT63hp/gKlDpNCJkRnMS';
    private const string ARGON2ID_HASH = '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQxMjM0NTY3OA$RdescudvJCsgt3ub+b+dWRWJTmaaJObG';

    private MessageBusInterface $messageBus;
    private UserAccountRepository $userAccountRepository;
    private PlayerRepository $playerRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->userAccountRepository = $container->get(UserAccountRepository::class);
        $this->playerRepository = $container->get(PlayerRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testImportCreatesAccountAndBackfillsPlayer(): void
    {
        $playerId = $this->createPlayer('auth0|import1', 'importtest1', email: null, name: null);

        $this->messageBus->dispatch(new ImportAuth0User(
            userId: 'auth0|import1',
            email: ' Import.One@Example.COM ',
            emailVerified: true,
            name: 'Import One',
            registeredAt: new DateTimeImmutable('2023-05-01T10:00:00+00:00'),
            passwordHash: self::BCRYPT_HASH,
        ));

        $userAccount = $this->userAccountRepository->findByUserId('auth0|import1');
        self::assertNotNull($userAccount);
        self::assertSame('import.one@example.com', $userAccount->email);
        self::assertSame(self::BCRYPT_HASH, $userAccount->password);
        self::assertTrue($userAccount->legacyAuth0);
        self::assertNotNull($userAccount->emailVerifiedAt);
        self::assertNull($userAccount->lastLoginAt);
        self::assertSame('2023-05-01 10:00:00', $userAccount->registeredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));

        $player = $this->playerRepository->get($playerId);
        self::assertSame('import.one@example.com', $player->email);
        self::assertSame('Import One', $player->name);
    }

    public function testImportIsIdempotentAndRefreshesBcryptHash(): void
    {
        $message = new ImportAuth0User(
            userId: 'auth0|import2',
            email: 'import.two@example.com',
            emailVerified: false,
            name: null,
            registeredAt: null,
            passwordHash: self::BCRYPT_HASH,
        );

        $this->messageBus->dispatch($message);
        $firstImport = $this->userAccountRepository->findByUserId('auth0|import2');
        self::assertNotNull($firstImport);
        $firstImportId = $firstImport->id->toString();
        self::assertNull($firstImport->emailVerifiedAt);

        // Re-run with a fresh export: newer bcrypt hash, email now verified
        $this->messageBus->dispatch(new ImportAuth0User(
            userId: 'auth0|import2',
            email: 'import.two@example.com',
            emailVerified: true,
            name: null,
            registeredAt: null,
            passwordHash: self::OTHER_BCRYPT_HASH,
        ));

        self::assertSame(1, $this->countAccountsWithEmail('import.two@example.com'));

        $userAccount = $this->userAccountRepository->findByUserId('auth0|import2');
        self::assertNotNull($userAccount);
        self::assertSame($firstImportId, $userAccount->id->toString());
        self::assertSame(self::OTHER_BCRYPT_HASH, $userAccount->password);
        self::assertNotNull($userAccount->emailVerifiedAt);
    }

    public function testImportNeverOverwritesNativelySetPassword(): void
    {
        $this->messageBus->dispatch(new ImportAuth0User(
            userId: 'auth0|import3',
            email: 'import.three@example.com',
            emailVerified: false,
            name: null,
            registeredAt: null,
            passwordHash: self::BCRYPT_HASH,
        ));

        // User logs in natively -> hash upgraded to argon2id (or sets a new password)
        $userAccount = $this->userAccountRepository->findByUserId('auth0|import3');
        self::assertNotNull($userAccount);
        $userAccount->changePassword(self::ARGON2ID_HASH);
        $this->entityManager->flush();

        // A late export back-fill must not regress the password to the stale bcrypt hash
        $this->messageBus->dispatch(new ImportAuth0User(
            userId: 'auth0|import3',
            email: 'import.three@example.com',
            emailVerified: false,
            name: null,
            registeredAt: null,
            passwordHash: self::BCRYPT_HASH,
        ));

        $userAccount = $this->userAccountRepository->findByUserId('auth0|import3');
        self::assertNotNull($userAccount);
        self::assertSame(self::ARGON2ID_HASH, $userAccount->password);
    }

    public function testImportSkipsUserWhenEmailBelongsToAnotherAccount(): void
    {
        $this->messageBus->dispatch(new ImportAuth0User(
            userId: 'auth0|import4a',
            email: 'import.four@example.com',
            emailVerified: true,
            name: null,
            registeredAt: null,
            passwordHash: self::BCRYPT_HASH,
        ));

        // Duplicate-email pair: same email under a different user_id must be skipped, not crash
        $this->messageBus->dispatch(new ImportAuth0User(
            userId: 'auth0|import4b',
            email: 'Import.Four@example.com',
            emailVerified: false,
            name: null,
            registeredAt: null,
            passwordHash: self::OTHER_BCRYPT_HASH,
        ));

        self::assertNull($this->userAccountRepository->findByUserId('auth0|import4b'));

        $userAccount = $this->userAccountRepository->findByUserId('auth0|import4a');
        self::assertNotNull($userAccount);
        self::assertSame(self::BCRYPT_HASH, $userAccount->password);
    }

    public function testImportWithoutPlayerRowStillCreatesAccount(): void
    {
        $this->messageBus->dispatch(new ImportAuth0User(
            userId: 'auth0|import5',
            email: 'import.five@example.com',
            emailVerified: false,
            name: 'No Player',
            registeredAt: null,
            passwordHash: null,
        ));

        $userAccount = $this->userAccountRepository->findByUserId('auth0|import5');
        self::assertNotNull($userAccount);
        self::assertNull($userAccount->password);
        self::assertTrue($userAccount->legacyAuth0);
    }

    public function testImportDoesNotOverwriteExistingPlayerProfile(): void
    {
        $playerId = $this->createPlayer('auth0|import6', 'importtest6', email: 'kept@example.com', name: 'Kept Name');

        $this->messageBus->dispatch(new ImportAuth0User(
            userId: 'auth0|import6',
            email: 'import.six@example.com',
            emailVerified: false,
            name: 'Imported Name',
            registeredAt: null,
            passwordHash: null,
        ));

        $player = $this->playerRepository->get($playerId);
        self::assertSame('kept@example.com', $player->email);
        self::assertSame('Kept Name', $player->name);
    }

    private function createPlayer(string $userId, string $code, null|string $email, null|string $name): string
    {
        $player = new Player(
            Uuid::uuid7(),
            $code,
            $userId,
            $email,
            $name,
            new DateTimeImmutable(),
        );

        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return $player->id->toString();
    }

    private function countAccountsWithEmail(string $email): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(user_account.id)')
            ->from(UserAccount::class, 'user_account')
            ->where('user_account.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }
}
