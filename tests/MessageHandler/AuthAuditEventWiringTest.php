<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Message\ChangeAccountEmail;
use SpeedPuzzling\Web\Message\ChangeAccountPassword;
use SpeedPuzzling\Web\Message\RequestPasswordReset;
use SpeedPuzzling\Web\Message\RequestSignInLink;
use SpeedPuzzling\Web\Message\SetAccountPassword;
use SpeedPuzzling\Web\Message\VerifyEmail;
use SpeedPuzzling\Web\Services\EmailVerificationTokenSigner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Every auth-path Messenger handler must leave its row in auth_audit_log.
 * The login success/failure/logout wiring lives in AuthenticationAuditSubscriberTest.
 */
final class AuthAuditEventWiringTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testSignInLinkRequestIsAuditedForKnownAndUnknownAddress(): void
    {
        $account = $this->createAccount('msp|audit-wire-1');

        $this->messageBus->dispatch(new RequestSignInLink($account->email, 'en'));

        self::assertSame(1, $this->countEvents('sign_in_link_requested', $account->email));
        self::assertSame($account->id->toString(), $this->fetchEvent('sign_in_link_requested', $account->email)['user_account_id']);

        $unknownEmail = sprintf('nobody+%s@example.com', bin2hex(random_bytes(4)));
        $this->messageBus->dispatch(new RequestSignInLink($unknownEmail, 'en'));

        $unknownRow = $this->fetchEvent('sign_in_link_requested', $unknownEmail);
        self::assertNull($unknownRow['user_account_id'], 'Unknown address is recorded account-less');
    }

    public function testPasswordResetRequestIsAudited(): void
    {
        $account = $this->createAccount('msp|audit-wire-2');

        $this->messageBus->dispatch(new RequestPasswordReset($account->email));

        self::assertSame(1, $this->countEvents('password_reset_requested', $account->email));
    }

    public function testBothPasswordDoorsAuditPasswordChanged(): void
    {
        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $setAccount = $this->createAccount('msp|audit-wire-3');
        $this->messageBus->dispatch(new SetAccountPassword('msp|audit-wire-3', 'new-strong-passphrase-1'));

        $setRow = $this->fetchEvent('password_changed', $setAccount->email);
        self::assertIsString($setRow['metadata']);
        self::assertSame(['method' => 'set_password'], json_decode($setRow['metadata'], associative: true));

        $changeAccount = $this->createAccount('msp|audit-wire-4');
        $changeAccount->changePassword($passwordHasher->hashPassword($changeAccount, 'current-passphrase-4'));
        $this->entityManager->flush();

        $this->messageBus->dispatch(new ChangeAccountPassword('msp|audit-wire-4', 'current-passphrase-4', 'new-strong-passphrase-4'));

        $changeRow = $this->fetchEvent('password_changed', $changeAccount->email);
        self::assertIsString($changeRow['metadata']);
        self::assertSame(['method' => 'change_password'], json_decode($changeRow['metadata'], associative: true));
    }

    public function testEmailChangeIsAuditedWithPreviousAddress(): void
    {
        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $account = $this->createAccount('msp|audit-wire-5');
        $previousEmail = $account->email;
        $account->changePassword($passwordHasher->hashPassword($account, 'current-passphrase-5'));
        $this->entityManager->flush();

        $newEmail = sprintf('changed+%s@example.com', bin2hex(random_bytes(4)));
        $this->messageBus->dispatch(new ChangeAccountEmail(
            userId: 'msp|audit-wire-5',
            newEmail: $newEmail,
            currentPassword: 'current-passphrase-5',
        ));

        $row = $this->fetchEvent('email_change_requested', $newEmail);
        self::assertSame($account->id->toString(), $row['user_account_id']);
        self::assertIsString($row['metadata']);
        self::assertSame(['previous_email' => $previousEmail], json_decode($row['metadata'], associative: true));
    }

    public function testEmailVerificationIsAudited(): void
    {
        $account = $this->createAccount('msp|audit-wire-6');
        $tokenSigner = self::getContainer()->get(EmailVerificationTokenSigner::class);
        $token = $tokenSigner->generate($account, new DateTimeImmutable(EmailVerificationTokenSigner::LIFETIME));

        $this->messageBus->dispatch(new VerifyEmail($token));

        self::assertSame(1, $this->countEvents('email_verified', $account->email));
    }

    private function createAccount(string $userId): UserAccount
    {
        $email = sprintf('wire+%s@example.com', bin2hex(random_bytes(4)));
        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());

        $this->entityManager->persist($userAccount);
        $this->entityManager->flush();

        return $userAccount;
    }

    private function countEvents(string $eventType, string $email): int
    {
        /** @var int|string $count */
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM auth_audit_log WHERE event_type = :type AND email = :email',
            ['type' => $eventType, 'email' => mb_strtolower($email)],
        );

        return (int) $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchEvent(string $eventType, string $email): array
    {
        /** @var false|array<string, mixed> $row */
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM auth_audit_log WHERE event_type = :type AND email = :email ORDER BY occurred_at DESC LIMIT 1',
            ['type' => $eventType, 'email' => mb_strtolower($email)],
        );

        self::assertNotFalse($row, sprintf('Expected a %s audit row for %s', $eventType, $email));

        return $row;
    }
}
