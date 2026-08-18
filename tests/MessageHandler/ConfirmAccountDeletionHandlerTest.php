<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\AccountDeletionRequest;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\AccountDeletionTokenExpired;
use SpeedPuzzling\Web\Exceptions\InvalidAccountDeletionToken;
use SpeedPuzzling\Web\Message\ConfirmAccountDeletion;
use SpeedPuzzling\Web\Repository\AccountDeletionRequestRepository;
use SpeedPuzzling\Web\Value\AccountDeletionToken;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The point of no return. What is pinned here: a live token really deletes the
 * player AND the account (and takes the request rows with it), and every dead
 * token - expired, forged, unknown - deletes nothing at all.
 */
final class ConfirmAccountDeletionHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private AccountDeletionRequestRepository $accountDeletionRequestRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->accountDeletionRequestRepository = $container->get(AccountDeletionRequestRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testALiveTokenDeletesThePlayerTheAccountAndTheRequest(): void
    {
        [$userAccount, $player] = $this->seedAccountWithPlayer();
        $token = $this->issueToken($userAccount, expiresAt: new DateTimeImmutable('+30 minutes'));

        $this->messageBus->dispatch(new ConfirmAccountDeletion($token->toString()));
        $this->entityManager->clear();

        self::assertNull($this->entityManager->find(Player::class, $player->id));
        self::assertNull($this->entityManager->find(UserAccount::class, $userAccount->id));
        self::assertNull($this->accountDeletionRequestRepository->findBySelector($token->selector), 'Request rows cascade with the account');
    }

    public function testAnAccountWithoutAPlayerRowIsRemovedToo(): void
    {
        $userAccount = $this->seedAccount();
        $token = $this->issueToken($userAccount, expiresAt: new DateTimeImmutable('+30 minutes'));

        $this->messageBus->dispatch(new ConfirmAccountDeletion($token->toString()));
        $this->entityManager->clear();

        self::assertNull($this->entityManager->find(UserAccount::class, $userAccount->id));
    }

    public function testAnExpiredTokenDeletesNothing(): void
    {
        [$userAccount, $player] = $this->seedAccountWithPlayer();
        $token = $this->issueToken($userAccount, expiresAt: new DateTimeImmutable('-1 minute'));

        try {
            $this->messageBus->dispatch(new ConfirmAccountDeletion($token->toString()));
            self::fail('Expected the expired token to be refused');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(AccountDeletionTokenExpired::class, $exception->getPrevious());
        }

        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Player::class, $player->id));
        self::assertNotNull($this->entityManager->find(UserAccount::class, $userAccount->id));
    }

    public function testAForgedVerifierDeletesNothing(): void
    {
        [$userAccount, $player] = $this->seedAccountWithPlayer();
        $token = $this->issueToken($userAccount, expiresAt: new DateTimeImmutable('+30 minutes'));
        $forged = $token->selector . strrev($token->verifier);

        try {
            $this->messageBus->dispatch(new ConfirmAccountDeletion($forged));
            self::fail('Expected the forged token to be refused');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(InvalidAccountDeletionToken::class, $exception->getPrevious());
        }

        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Player::class, $player->id));
        self::assertNotNull($this->entityManager->find(UserAccount::class, $userAccount->id));
    }

    public function testAnUnknownTokenIsInvalid(): void
    {
        try {
            $this->messageBus->dispatch(new ConfirmAccountDeletion(AccountDeletionToken::generate()->toString()));
            self::fail('Expected the unknown token to be refused');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(InvalidAccountDeletionToken::class, $exception->getPrevious());
        }
    }

    public function testATokenCanOnlyBeUsedOnce(): void
    {
        [$userAccount] = $this->seedAccountWithPlayer();
        $token = $this->issueToken($userAccount, expiresAt: new DateTimeImmutable('+30 minutes'));

        $this->messageBus->dispatch(new ConfirmAccountDeletion($token->toString()));

        try {
            $this->messageBus->dispatch(new ConfirmAccountDeletion($token->toString()));
            self::fail('Expected the replay to be refused');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(InvalidAccountDeletionToken::class, $exception->getPrevious());
        }
    }

    public function testOtherAccountsAreLeftAlone(): void
    {
        [$leaving] = $this->seedAccountWithPlayer();
        [$staying, $stayingPlayer] = $this->seedAccountWithPlayer();
        $stayingToken = $this->issueToken($staying, expiresAt: new DateTimeImmutable('+30 minutes'));
        $token = $this->issueToken($leaving, expiresAt: new DateTimeImmutable('+30 minutes'));

        $this->messageBus->dispatch(new ConfirmAccountDeletion($token->toString()));
        $this->entityManager->clear();

        self::assertNotNull($this->entityManager->find(Player::class, $stayingPlayer->id));
        self::assertNotNull($this->entityManager->find(UserAccount::class, $staying->id));
        self::assertNotNull($this->accountDeletionRequestRepository->findBySelector($stayingToken->selector));
    }

    private function issueToken(UserAccount $userAccount, DateTimeImmutable $expiresAt): AccountDeletionToken
    {
        $token = AccountDeletionToken::generate();

        $this->accountDeletionRequestRepository->save(new AccountDeletionRequest(
            Uuid::uuid7(),
            $userAccount,
            $token->selector,
            $token->hashedVerifier(),
            $expiresAt->modify('-60 minutes'),
            $expiresAt,
        ));
        $this->entityManager->flush();

        return $token;
    }

    private function seedAccount(): UserAccount
    {
        $userAccount = new UserAccount(
            Uuid::uuid7(),
            'msp|' . bin2hex(random_bytes(4)),
            sprintf('confirm.delete+%s@example.com', bin2hex(random_bytes(4))),
            new DateTimeImmutable(),
        );

        $this->entityManager->persist($userAccount);
        $this->entityManager->flush();

        return $userAccount;
    }

    /**
     * @return array{UserAccount, Player}
     */
    private function seedAccountWithPlayer(): array
    {
        $userAccount = $this->seedAccount();
        $player = new Player(Uuid::uuid7(), 'CDL' . bin2hex(random_bytes(2)), $userAccount->userId, $userAccount->email, 'Leaving Soon', new DateTimeImmutable());

        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return [$userAccount, $player];
    }
}
