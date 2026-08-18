<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\AccountDeletionRequest;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\UserAccountNotFound;
use SpeedPuzzling\Web\Message\RequestAccountDeletion;
use SpeedPuzzling\Web\Repository\AccountDeletionRequestRepository;
use SpeedPuzzling\Web\Value\AccountDeletionToken;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class RequestAccountDeletionHandlerTest extends KernelTestCase
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

    public function testMintsATokenAndStoresOnlyTheHashOfItsVerifier(): void
    {
        $userAccount = $this->createUserAccount();

        $token = $this->requestDeletion($userAccount->userId);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token->toString());

        $request = $this->accountDeletionRequestRepository->findBySelector($token->selector);
        self::assertNotNull($request);
        self::assertSame($userAccount->userId, $request->userAccount->userId);
        self::assertSame($token->hashedVerifier(), $request->hashedVerifier);
        self::assertNotSame($token->verifier, $request->hashedVerifier);
    }

    public function testTheLinkLivesForOneHour(): void
    {
        $userAccount = $this->createUserAccount();

        $token = $this->requestDeletion($userAccount->userId);

        $request = $this->accountDeletionRequestRepository->findBySelector($token->selector);
        self::assertNotNull($request);
        self::assertSame(
            $request->requestedAt->modify('+60 minutes')->getTimestamp(),
            $request->expiresAt->getTimestamp(),
        );
        self::assertFalse($request->isExpired($request->requestedAt->modify('+59 minutes')));
        self::assertTrue($request->isExpired($request->requestedAt->modify('+60 minutes')));
    }

    public function testThrowsForAnUnknownAccount(): void
    {
        $this->expectException(UserAccountNotFound::class);

        $this->requestDeletion('msp|' . bin2hex(random_bytes(4)));
    }

    /**
     * No silent throttle here (the caller is signed in, so nothing can be
     * enumerated): asking again replaces the older link, so "it did not arrive,
     * send another" just works and exactly one link is live per account.
     */
    public function testANewRequestReplacesTheOlderOne(): void
    {
        $userAccount = $this->createUserAccount();

        $first = $this->requestDeletion($userAccount->userId);
        $second = $this->requestDeletion($userAccount->userId);

        self::assertNotSame($first->toString(), $second->toString());
        self::assertNull($this->accountDeletionRequestRepository->findBySelector($first->selector));
        self::assertNotNull($this->accountDeletionRequestRepository->findBySelector($second->selector));
    }

    public function testDoesNotTouchOtherAccountsRequests(): void
    {
        $one = $this->createUserAccount();
        $other = $this->createUserAccount();

        $otherToken = $this->requestDeletion($other->userId);
        $this->requestDeletion($one->userId);

        self::assertNotNull($this->accountDeletionRequestRepository->findBySelector($otherToken->selector));
    }

    public function testGarbageCollectsLongExpiredRequests(): void
    {
        $userAccount = $this->createUserAccount();
        $bystander = $this->createUserAccount();

        $longExpired = AccountDeletionToken::generate();
        $this->accountDeletionRequestRepository->save(new AccountDeletionRequest(
            Uuid::uuid7(),
            $bystander,
            $longExpired->selector,
            $longExpired->hashedVerifier(),
            new DateTimeImmutable('-9 days'),
            new DateTimeImmutable('-8 days'),
        ));

        // Recently expired rows survive so their links can still say "expired"
        $recentlyExpired = AccountDeletionToken::generate();
        $this->accountDeletionRequestRepository->save(new AccountDeletionRequest(
            Uuid::uuid7(),
            $bystander,
            $recentlyExpired->selector,
            $recentlyExpired->hashedVerifier(),
            new DateTimeImmutable('-2 hours'),
            new DateTimeImmutable('-1 hour'),
        ));
        $this->entityManager->flush();

        $this->requestDeletion($userAccount->userId);

        self::assertNull($this->accountDeletionRequestRepository->findBySelector($longExpired->selector));
        self::assertNotNull($this->accountDeletionRequestRepository->findBySelector($recentlyExpired->selector));
    }

    public function testRecordsTheRequestInTheAuditTrail(): void
    {
        $userAccount = $this->createUserAccount();

        $this->requestDeletion($userAccount->userId);

        /** @var int|string $count */
        $count = $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM auth_audit_log WHERE user_account_id = :id AND event_type = :type',
            ['id' => $userAccount->id->toString(), 'type' => 'account_deletion_requested'],
        );

        self::assertSame(1, (int) $count);
    }

    private function requestDeletion(string $userId): AccountDeletionToken
    {
        $envelope = $this->messageBus->dispatch(new RequestAccountDeletion($userId));

        /** @var HandledStamp $stamp */
        $stamp = $envelope->last(HandledStamp::class);
        $token = $stamp->getResult();
        assert($token instanceof AccountDeletionToken);

        return $token;
    }

    private function createUserAccount(): UserAccount
    {
        $userAccount = new UserAccount(
            Uuid::uuid7(),
            'msp|' . bin2hex(random_bytes(4)),
            sprintf('delete.request+%s@example.com', bin2hex(random_bytes(4))),
            new DateTimeImmutable(),
        );

        $this->entityManager->persist($userAccount);
        $this->entityManager->flush();

        return $userAccount;
    }
}
