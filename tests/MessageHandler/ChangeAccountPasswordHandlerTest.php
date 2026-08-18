<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ResetPasswordRequest;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\CurrentPasswordDoesNotMatch;
use SpeedPuzzling\Web\Message\ChangeAccountPassword;
use SpeedPuzzling\Web\Repository\ResetPasswordRequestRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Value\PasswordResetToken;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ChangeAccountPasswordHandlerTest extends KernelTestCase
{
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

    public function testChangesThePasswordToTheNewOne(): void
    {
        $this->createUserAccountWithPassword('msp|chpass1', 'chpass.one@example.com', 'old-passphrase-1');

        $this->messageBus->dispatch(new ChangeAccountPassword('msp|chpass1', 'old-passphrase-1', 'new-passphrase-1'));

        $userAccount = $this->userAccountRepository->findByUserId('msp|chpass1');
        self::assertNotNull($userAccount);
        self::assertTrue($this->passwordHasher->isPasswordValid($userAccount, 'new-passphrase-1'));
        self::assertFalse($this->passwordHasher->isPasswordValid($userAccount, 'old-passphrase-1'));
    }

    public function testLegacyBcryptCurrentPasswordIsAcceptedAndRehashedToArgon2id(): void
    {
        // An imported Auth0 account still carries the bcrypt hash Auth0 exported
        $userAccount = $this->createUserAccount('msp|chpass2', 'chpass.two@example.com');
        $userAccount->changePassword(password_hash('legacy-bcrypt-passphrase', PASSWORD_BCRYPT));
        $this->entityManager->flush();

        $this->messageBus->dispatch(
            new ChangeAccountPassword('msp|chpass2', 'legacy-bcrypt-passphrase', 'new-passphrase-2'),
        );

        $userAccount = $this->userAccountRepository->findByUserId('msp|chpass2');
        self::assertNotNull($userAccount);
        self::assertNotNull($userAccount->password);
        self::assertStringStartsWith('$argon2id', $userAccount->password);
        self::assertStringStartsNotWith('$2', $userAccount->password);
        self::assertTrue($this->passwordHasher->isPasswordValid($userAccount, 'new-passphrase-2'));
    }

    public function testWrongCurrentPasswordIsRejectedAndLeavesTheHashUntouched(): void
    {
        $userAccount = $this->createUserAccountWithPassword('msp|chpass3', 'chpass.three@example.com', 'old-passphrase-3');
        $hashBefore = $userAccount->password;

        $this->expectCurrentPasswordRejected(
            new ChangeAccountPassword('msp|chpass3', 'not-the-current-passphrase', 'new-passphrase-3'),
        );

        $userAccount = $this->userAccountRepository->findByUserId('msp|chpass3');
        self::assertNotNull($userAccount);
        self::assertSame($hashBefore, $userAccount->password);
        self::assertTrue($this->passwordHasher->isPasswordValid($userAccount, 'old-passphrase-3'));
    }

    public function testAccountWithoutAnyPasswordIsRejected(): void
    {
        // Legacy account that has only ever been reached through the sign-in link
        $this->createUserAccount('msp|chpass4', 'chpass.four@example.com');

        $this->expectCurrentPasswordRejected(
            new ChangeAccountPassword('msp|chpass4', 'anything-at-all', 'new-passphrase-4'),
        );

        $userAccount = $this->userAccountRepository->findByUserId('msp|chpass4');
        self::assertNotNull($userAccount);
        self::assertNull($userAccount->password);
    }

    public function testSuccessfulChangeRemovesOutstandingResetRequests(): void
    {
        $userAccount = $this->createUserAccountWithPassword('msp|chpass5', 'chpass.five@example.com', 'old-passphrase-5');

        $token = PasswordResetToken::generate();
        $requestedAt = new DateTimeImmutable();
        $this->resetPasswordRequestRepository->save(new ResetPasswordRequest(
            Uuid::uuid7(),
            $userAccount,
            $token->selector,
            $token->hashedVerifier(),
            $requestedAt,
            $requestedAt->modify(ResetPasswordRequest::LIFETIME),
        ));
        $this->entityManager->flush();

        self::assertNotNull($this->resetPasswordRequestRepository->findBySelector($token->selector));

        $this->messageBus->dispatch(new ChangeAccountPassword('msp|chpass5', 'old-passphrase-5', 'new-passphrase-5'));

        self::assertNull($this->resetPasswordRequestRepository->findBySelector($token->selector));
    }

    private function expectCurrentPasswordRejected(ChangeAccountPassword $message): void
    {
        try {
            $this->messageBus->dispatch($message);
            self::fail('Expected CurrentPasswordDoesNotMatch was not thrown');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(CurrentPasswordDoesNotMatch::class, $e->getPrevious());
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
}
