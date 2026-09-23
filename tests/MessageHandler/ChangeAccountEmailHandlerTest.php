<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\CurrentPasswordDoesNotMatch;
use SpeedPuzzling\Web\Exceptions\EmailAlreadyRegistered;
use SpeedPuzzling\Web\Message\ChangeAccountEmail;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ChangeAccountEmailHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private UserAccountRepository $userAccountRepository;
    private UserPasswordHasherInterface $passwordHasher;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->userAccountRepository = $container->get(UserAccountRepository::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testChangesEmailEverywhereAndResetsVerification(): void
    {
        $userAccount = $this->createUserAccountWithPassword('msp|chmail1', 'chmail.one@example.com', 'passphrase-1');
        $userAccount->markEmailVerified(new DateTimeImmutable());
        $this->createPlayer('msp|chmail1', 'chmail1');
        $this->entityManager->flush();

        $this->messageBus->dispatch(
            new ChangeAccountEmail('msp|chmail1', ' Chmail.One.NEW@Example.COM ', 'passphrase-1'),
        );

        $userAccount = $this->userAccountRepository->findByUserId('msp|chmail1');
        self::assertNotNull($userAccount);
        self::assertSame('chmail.one.new@example.com', $userAccount->email);
        // The new address is unproven until its own link is clicked
        self::assertNull($userAccount->emailVerifiedAt);
    }

    public function testWrongCurrentPasswordChangesNothing(): void
    {
        $userAccount = $this->createUserAccountWithPassword('msp|chmail2', 'chmail.two@example.com', 'passphrase-2');
        $userAccount->markEmailVerified(new DateTimeImmutable());
        $this->createPlayer('msp|chmail2', 'chmail2');
        $this->entityManager->flush();

        $this->expectHandlerException(
            new ChangeAccountEmail('msp|chmail2', 'chmail.two.new@example.com', 'not-the-passphrase'),
            CurrentPasswordDoesNotMatch::class,
        );

        $userAccount = $this->userAccountRepository->findByUserId('msp|chmail2');
        self::assertNotNull($userAccount);
        self::assertSame('chmail.two@example.com', $userAccount->email);
        self::assertNotNull($userAccount->emailVerifiedAt);
    }

    public function testAddressAlreadyOnAnotherAccountIsRejected(): void
    {
        $this->createUserAccountWithPassword('msp|chmail3', 'chmail.three@example.com', 'passphrase-3');
        $this->createUserAccount('msp|chmail3-other', 'chmail.taken@example.com');

        $this->expectHandlerException(
            new ChangeAccountEmail('msp|chmail3', 'Chmail.TAKEN@example.com', 'passphrase-3'),
            EmailAlreadyRegistered::class,
        );

        $userAccount = $this->userAccountRepository->findByUserId('msp|chmail3');
        self::assertNotNull($userAccount);
        self::assertSame('chmail.three@example.com', $userAccount->email);
    }

    /**
     * user_account.email is the single source of truth: a player row without an account
     * (an Auth0-era leftover) has no address at all, so it reserves nothing - only
     * another ACCOUNT can hold the address.
     */
    public function testALegacyPlayerWithoutAccountReservesNoAddress(): void
    {
        $this->createUserAccountWithPassword('msp|chmail4', 'chmail.four@example.com', 'passphrase-4');
        $this->createPlayer('msp|chmail4', 'chmail4');
        $this->createPlayer('auth0|chmail4-legacy', 'chmail4legacy');
        $this->entityManager->flush();

        self::assertNull($this->userAccountRepository->findByEmail('chmail.legacy@example.com'));

        $this->messageBus->dispatch(
            new ChangeAccountEmail('msp|chmail4', 'chmail.legacy@example.com', 'passphrase-4'),
        );

        $userAccount = $this->userAccountRepository->findByUserId('msp|chmail4');
        self::assertNotNull($userAccount);
        self::assertSame('chmail.legacy@example.com', $userAccount->email);
    }

    public function testChangingToTheSameAddressInDifferentCaseIsANoOp(): void
    {
        $userAccount = $this->createUserAccountWithPassword('msp|chmail5', 'chmail.five@example.com', 'passphrase-5');
        $userAccount->markEmailVerified(new DateTimeImmutable());
        $this->entityManager->flush();

        $verifiedAt = $userAccount->emailVerifiedAt;
        self::assertNotNull($verifiedAt);

        $this->messageBus->dispatch(
            new ChangeAccountEmail('msp|chmail5', ' CHMAIL.Five@Example.com ', 'passphrase-5'),
        );

        $userAccount = $this->userAccountRepository->findByUserId('msp|chmail5');
        self::assertNotNull($userAccount);
        self::assertSame('chmail.five@example.com', $userAccount->email);
        // Re-typing your own address must not cost you your verified status
        self::assertEquals($verifiedAt, $userAccount->emailVerifiedAt);
    }

    /**
     * @param class-string<\Throwable> $expectedException
     */
    private function expectHandlerException(ChangeAccountEmail $message, string $expectedException): void
    {
        try {
            $this->messageBus->dispatch($message);
            self::fail(sprintf('Expected %s was not thrown', $expectedException));
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf($expectedException, $e->getPrevious());
        }
    }

    private function createUserAccount(string $userId, string $email): UserAccount
    {
        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());

        $this->entityManager->persist($userAccount);
        $this->entityManager->flush();

        return $userAccount;
    }

    private function createUserAccountWithPassword(string $userId, string $email, string $plainPassword): UserAccount
    {
        $userAccount = $this->createUserAccount($userId, $email);
        $userAccount->changePassword($this->passwordHasher->hashPassword($userAccount, $plainPassword));
        $this->entityManager->flush();

        return $userAccount;
    }

    private function createPlayer(string $userId, string $code): Player
    {
        $player = new Player(
            Uuid::uuid7(),
            $code,
            $userId,
            null,
            new DateTimeImmutable(),
        );

        $this->entityManager->persist($player);

        return $player;
    }
}
