<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\EmailAlreadyRegistered;
use SpeedPuzzling\Web\Message\RegisterUser;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegisterUserHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private UserAccountRepository $userAccountRepository;
    private PlayerRepository $playerRepository;
    private UserPasswordHasherInterface $passwordHasher;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->userAccountRepository = $container->get(UserAccountRepository::class);
        $this->playerRepository = $container->get(PlayerRepository::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testRegistrationCreatesAccountAndPlayerTogether(): void
    {
        $userId = $this->register(' Register.One@EXAMPLE.com ', 'a-strong-passphrase-1', 'cs');

        self::assertStringStartsWith('msp|', $userId);

        $userAccount = $this->userAccountRepository->findByUserId($userId);
        self::assertNotNull($userAccount);
        self::assertSame('register.one@example.com', $userAccount->email);
        self::assertFalse($userAccount->legacyAuth0);
        self::assertNull($userAccount->emailVerifiedAt);

        // Never the plaintext, and the stored hash must actually verify against it
        self::assertNotNull($userAccount->password);
        self::assertNotSame('a-strong-passphrase-1', $userAccount->password);
        self::assertTrue($this->passwordHasher->isPasswordValid($userAccount, 'a-strong-passphrase-1'));

        $player = $this->playerRepository->findByUserId($userId);
        self::assertNotNull($player);
        self::assertSame($userId, $player->userId);
        self::assertSame('register.one@example.com', $player->email);
        self::assertNotSame('', $player->code);
        self::assertSame('cs', $player->locale);
    }

    public function testEmailAlreadyOnAnAccountIsRejectedRegardlessOfCase(): void
    {
        $this->createUserAccount('msp|register2', 'register.two@example.com');

        $this->expectRegistrationRejected(' Register.TWO@Example.COM ');

        // The colliding registration must not have left anything behind
        self::assertSame(1, $this->countAccountsWithEmail('register.two@example.com'));
    }

    public function testEmailOfLegacyPlayerWithoutAccountIsRejected(): void
    {
        // Window A: an Auth0 user before the Stage B import has a player row but no
        // user_account. Registering natively on their address would strand their profile.
        $this->createPlayer('auth0|register3', 'reg-legacy-3', 'Legacy.Three@Example.com', locale: null);

        self::assertNull($this->userAccountRepository->findByEmail('legacy.three@example.com'));

        $this->expectRegistrationRejected('legacy.three@example.com');

        self::assertSame(0, $this->countAccountsWithEmail('legacy.three@example.com'));
    }

    public function testRegistrationWithoutLocaleLeavesPlayerLocaleNull(): void
    {
        $userId = $this->register('register.four@example.com', 'a-strong-passphrase-4', null);

        $player = $this->playerRepository->findByUserId($userId);
        self::assertNotNull($player);
        self::assertNull($player->locale);
    }

    private function register(string $email, string $plainPassword, null|string $locale): string
    {
        $envelope = $this->messageBus->dispatch(new RegisterUser($email, $plainPassword, $locale));

        $handledStamp = $envelope->last(HandledStamp::class);
        self::assertNotNull($handledStamp);

        $userId = $handledStamp->getResult();
        self::assertIsString($userId);

        return $userId;
    }

    private function expectRegistrationRejected(string $email): void
    {
        try {
            $this->messageBus->dispatch(new RegisterUser($email, 'a-strong-passphrase', null));
            self::fail('Expected EmailAlreadyRegistered was not thrown');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(EmailAlreadyRegistered::class, $e->getPrevious());
        }
    }

    private function createUserAccount(string $userId, string $email): UserAccount
    {
        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());

        $this->entityManager->persist($userAccount);
        $this->entityManager->flush();

        return $userAccount;
    }

    private function createPlayer(string $userId, string $code, null|string $email, null|string $locale): Player
    {
        $player = new Player(
            Uuid::uuid7(),
            $code,
            $userId,
            $email,
            null,
            new DateTimeImmutable(),
        );

        if ($locale !== null) {
            $player->changeLocale($locale);
        }

        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return $player;
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
