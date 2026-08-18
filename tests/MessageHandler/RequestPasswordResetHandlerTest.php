<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ResetPasswordRequest;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Message\RequestPasswordReset;
use SpeedPuzzling\Web\Repository\ResetPasswordRequestRepository;
use SpeedPuzzling\Web\Value\PasswordResetToken;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class RequestPasswordResetHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private ResetPasswordRequestRepository $resetPasswordRequestRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->resetPasswordRequestRepository = $container->get(ResetPasswordRequestRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testCreatesTokenAndPersistsRequest(): void
    {
        $this->createUserAccount('msp|reset1', 'reset.one@example.com');

        $token = $this->requestPasswordReset('Reset.One@EXAMPLE.com');

        self::assertNotNull($token);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token->toString());

        $request = $this->resetPasswordRequestRepository->findBySelector($token->selector);
        self::assertNotNull($request);
        self::assertSame($token->hashedVerifier(), $request->hashedVerifier);
        self::assertNotSame($token->verifier, $request->hashedVerifier);
        self::assertTrue($request->expiresAt > $request->requestedAt);
        self::assertSame('msp|reset1', $request->userAccount->userId);
    }

    public function testReturnsNullForUnknownEmail(): void
    {
        self::assertNull($this->requestPasswordReset('nobody@example.com'));
    }

    public function testThrottlesWhileActiveRequestExists(): void
    {
        $this->createUserAccount('msp|reset2', 'reset.two@example.com');

        self::assertNotNull($this->requestPasswordReset('reset.two@example.com'));
        self::assertNull($this->requestPasswordReset('reset.two@example.com'));
    }

    public function testAllowsNewRequestAfterPreviousExpired(): void
    {
        $userAccount = $this->createUserAccount('msp|reset3', 'reset.three@example.com');
        $this->createRequest($userAccount, PasswordResetToken::generate(), expiresAt: new DateTimeImmutable('-1 minute'));

        self::assertNotNull($this->requestPasswordReset('reset.three@example.com'));
    }

    public function testGarbageCollectsLongExpiredRequests(): void
    {
        $userAccount = $this->createUserAccount('msp|reset4', 'reset.four@example.com');
        $longExpiredToken = PasswordResetToken::generate();
        $this->createRequest($userAccount, $longExpiredToken, expiresAt: new DateTimeImmutable('-8 days'));

        self::assertNotNull($this->requestPasswordReset('reset.four@example.com'));
        self::assertNull($this->resetPasswordRequestRepository->findBySelector($longExpiredToken->selector));
    }

    private function requestPasswordReset(string $email): null|PasswordResetToken
    {
        $envelope = $this->messageBus->dispatch(new RequestPasswordReset($email));

        $handledStamp = $envelope->last(HandledStamp::class);
        self::assertNotNull($handledStamp);

        /** @var null|PasswordResetToken $token */
        $token = $handledStamp->getResult();

        return $token;
    }

    private function createUserAccount(string $userId, string $email): UserAccount
    {
        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());

        $this->entityManager->persist($userAccount);
        $this->entityManager->flush();

        return $userAccount;
    }

    private function createRequest(UserAccount $userAccount, PasswordResetToken $token, DateTimeImmutable $expiresAt): void
    {
        $this->entityManager->persist(new ResetPasswordRequest(
            Uuid::uuid7(),
            $userAccount,
            $token->selector,
            $token->hashedVerifier(),
            $expiresAt->modify('-1 hour'),
            $expiresAt,
        ));
        $this->entityManager->flush();
    }
}
