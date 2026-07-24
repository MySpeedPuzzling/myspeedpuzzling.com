<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\EmailVerificationTokenExpired;
use SpeedPuzzling\Web\Exceptions\InvalidEmailVerificationToken;
use SpeedPuzzling\Web\Message\VerifyEmail;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Services\EmailVerificationTokenSigner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class VerifyEmailHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private EmailVerificationTokenSigner $tokenSigner;
    private UserAccountRepository $userAccountRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->tokenSigner = $container->get(EmailVerificationTokenSigner::class);
        $this->userAccountRepository = $container->get(UserAccountRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testValidTokenMarksEmailVerified(): void
    {
        $userAccount = $this->createUserAccount('msp|verify1', 'verify.one@example.com');
        $token = $this->tokenSigner->generate($userAccount, new DateTimeImmutable(EmailVerificationTokenSigner::LIFETIME));

        $this->messageBus->dispatch(new VerifyEmail($token));

        $userAccount = $this->userAccountRepository->findByUserId('msp|verify1');
        self::assertNotNull($userAccount);
        self::assertNotNull($userAccount->emailVerifiedAt);
    }

    public function testVerificationIsIdempotent(): void
    {
        $userAccount = $this->createUserAccount('msp|verify2', 'verify.two@example.com');
        $token = $this->tokenSigner->generate($userAccount, new DateTimeImmutable(EmailVerificationTokenSigner::LIFETIME));

        $this->messageBus->dispatch(new VerifyEmail($token));
        $verifiedAccount = $this->userAccountRepository->findByUserId('msp|verify2');
        self::assertNotNull($verifiedAccount);
        $firstVerifiedAt = $verifiedAccount->emailVerifiedAt;
        self::assertNotNull($firstVerifiedAt);

        $this->messageBus->dispatch(new VerifyEmail($token));
        $verifiedAccount = $this->userAccountRepository->findByUserId('msp|verify2');
        self::assertNotNull($verifiedAccount);
        self::assertEquals($firstVerifiedAt, $verifiedAccount->emailVerifiedAt);
    }

    public function testTamperedTokenIsRejected(): void
    {
        $userAccount = $this->createUserAccount('msp|verify3', 'verify.three@example.com');
        $token = $this->tokenSigner->generate($userAccount, new DateTimeImmutable(EmailVerificationTokenSigner::LIFETIME));

        $tamperedToken = $token[5] === 'A'
            ? substr_replace($token, 'B', 5, 1)
            : substr_replace($token, 'A', 5, 1);

        $this->expectHandlerException(new VerifyEmail($tamperedToken), InvalidEmailVerificationToken::class);
        $this->expectHandlerException(new VerifyEmail('garbage'), InvalidEmailVerificationToken::class);

        $userAccount = $this->userAccountRepository->findByUserId('msp|verify3');
        self::assertNotNull($userAccount);
        self::assertNull($userAccount->emailVerifiedAt);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $userAccount = $this->createUserAccount('msp|verify4', 'verify.four@example.com');
        $token = $this->tokenSigner->generate($userAccount, new DateTimeImmutable('-1 minute'));

        $this->expectHandlerException(new VerifyEmail($token), EmailVerificationTokenExpired::class);
    }

    public function testTokenForPreviousEmailIsRejectedAfterEmailChange(): void
    {
        $userAccount = $this->createUserAccount('msp|verify5', 'verify.five@example.com');
        $userAccount->markEmailVerified(new DateTimeImmutable());
        $token = $this->tokenSigner->generate($userAccount, new DateTimeImmutable(EmailVerificationTokenSigner::LIFETIME));

        $userAccount->changeEmail('Verify.Five.New@Example.com');
        $this->entityManager->flush();

        $changedAccount = $this->userAccountRepository->findByUserId('msp|verify5');
        self::assertNotNull($changedAccount);
        self::assertSame('verify.five.new@example.com', $changedAccount->email);
        // Changing the address resets verification until the new link is clicked
        self::assertNull($changedAccount->emailVerifiedAt);

        $this->expectHandlerException(new VerifyEmail($token), InvalidEmailVerificationToken::class);

        $changedAccount = $this->userAccountRepository->findByUserId('msp|verify5');
        self::assertNotNull($changedAccount);
        self::assertNull($changedAccount->emailVerifiedAt);
    }

    public function testTokenForUnknownAccountIsRejected(): void
    {
        // Never persisted - simulates an account deleted between send and click
        $ghostAccount = new UserAccount(Uuid::uuid7(), 'msp|verify-ghost', 'verify.ghost@example.com', new DateTimeImmutable());
        $token = $this->tokenSigner->generate($ghostAccount, new DateTimeImmutable(EmailVerificationTokenSigner::LIFETIME));

        $this->expectHandlerException(new VerifyEmail($token), InvalidEmailVerificationToken::class);
    }

    /**
     * @param class-string<\Throwable> $expectedException
     */
    private function expectHandlerException(VerifyEmail $message, string $expectedException): void
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
}
