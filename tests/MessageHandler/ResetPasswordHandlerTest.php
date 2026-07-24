<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ResetPasswordRequest;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\InvalidPasswordResetToken;
use SpeedPuzzling\Web\Exceptions\PasswordResetTokenExpired;
use SpeedPuzzling\Web\Message\RequestPasswordReset;
use SpeedPuzzling\Web\Message\ResetPassword;
use SpeedPuzzling\Web\Repository\ResetPasswordRequestRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Value\PasswordResetToken;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ResetPasswordHandlerTest extends KernelTestCase
{
    private const string BCRYPT_HASH = '$2b$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy';

    private MessageBusInterface $messageBus;
    private UserAccountRepository $userAccountRepository;
    private ResetPasswordRequestRepository $resetPasswordRequestRepository;
    private UserPasswordHasherInterface $passwordHasher;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->userAccountRepository = $container->get(UserAccountRepository::class);
        $this->resetPasswordRequestRepository = $container->get(ResetPasswordRequestRepository::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testResetChangesPasswordToArgon2idAndConsumesToken(): void
    {
        $this->createUserAccount('auth0|pwreset1', 'pwreset.one@example.com', self::BCRYPT_HASH);
        $token = $this->requestPasswordReset('pwreset.one@example.com');

        $this->messageBus->dispatch(new ResetPassword($token->toString(), 'brand-new-password-123'));

        $userAccount = $this->userAccountRepository->findByUserId('auth0|pwreset1');
        self::assertNotNull($userAccount);
        self::assertNotNull($userAccount->password);
        self::assertStringStartsWith('$argon2id$', $userAccount->password);
        self::assertTrue($this->passwordHasher->isPasswordValid($userAccount, 'brand-new-password-123'));

        self::assertNull($this->resetPasswordRequestRepository->findBySelector($token->selector));
    }

    public function testTokenCannotBeReused(): void
    {
        $this->createUserAccount('auth0|pwreset2', 'pwreset.two@example.com', self::BCRYPT_HASH);
        $token = $this->requestPasswordReset('pwreset.two@example.com');

        $this->messageBus->dispatch(new ResetPassword($token->toString(), 'first-new-password-123'));

        $this->expectHandlerException(
            new ResetPassword($token->toString(), 'second-new-password-123'),
            InvalidPasswordResetToken::class,
        );
    }

    public function testResetInvalidatesOtherOpenRequests(): void
    {
        $userAccount = $this->createUserAccount('auth0|pwreset3', 'pwreset.three@example.com', self::BCRYPT_HASH);
        $tokenA = $this->requestPasswordReset('pwreset.three@example.com');
        // A second open request cannot exist via the throttled handler - insert it directly
        $tokenB = PasswordResetToken::generate();
        $this->createRequest($userAccount, $tokenB, expiresAt: new DateTimeImmutable('+1 hour'));

        $this->messageBus->dispatch(new ResetPassword($tokenA->toString(), 'brand-new-password-123'));

        self::assertNull($this->resetPasswordRequestRepository->findBySelector($tokenB->selector));
    }

    public function testMalformedTokenIsRejected(): void
    {
        $this->expectHandlerException(
            new ResetPassword('definitely-not-a-valid-token', 'whatever-password-123'),
            InvalidPasswordResetToken::class,
        );
    }

    public function testWrongVerifierIsRejected(): void
    {
        $this->createUserAccount('auth0|pwreset4', 'pwreset.four@example.com', self::BCRYPT_HASH);
        $token = $this->requestPasswordReset('pwreset.four@example.com');

        $this->expectHandlerException(
            new ResetPassword($token->selector . str_repeat('0', 32), 'whatever-password-123'),
            InvalidPasswordResetToken::class,
        );

        $userAccount = $this->userAccountRepository->findByUserId('auth0|pwreset4');
        self::assertNotNull($userAccount);
        self::assertSame(self::BCRYPT_HASH, $userAccount->password);
    }

    public function testExpiredTokenIsRejected(): void
    {
        $userAccount = $this->createUserAccount('auth0|pwreset5', 'pwreset.five@example.com', self::BCRYPT_HASH);
        $expiredToken = PasswordResetToken::generate();
        $this->createRequest($userAccount, $expiredToken, expiresAt: new DateTimeImmutable('-1 minute'));

        $this->expectHandlerException(
            new ResetPassword($expiredToken->toString(), 'whatever-password-123'),
            PasswordResetTokenExpired::class,
        );
    }

    private function requestPasswordReset(string $email): PasswordResetToken
    {
        $envelope = $this->messageBus->dispatch(new RequestPasswordReset($email));

        $handledStamp = $envelope->last(HandledStamp::class);
        self::assertNotNull($handledStamp);

        $token = $handledStamp->getResult();
        self::assertInstanceOf(PasswordResetToken::class, $token);

        return $token;
    }

    /**
     * @param class-string<\Throwable> $expectedException
     */
    private function expectHandlerException(ResetPassword $message, string $expectedException): void
    {
        try {
            $this->messageBus->dispatch($message);
            self::fail(sprintf('Expected %s was not thrown', $expectedException));
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf($expectedException, $e->getPrevious());
        }
    }

    private function createUserAccount(string $userId, string $email, string $password): UserAccount
    {
        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());
        $userAccount->changePassword($password);

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
