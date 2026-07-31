<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Message\RecordAuthAuditEvent;
use SpeedPuzzling\Web\Value\AuthAuditEventType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class RecordAuthAuditEventHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testResolvesAccountFromUserId(): void
    {
        $account = $this->createAccount('msp|audit-user-id');

        $this->messageBus->dispatch(new RecordAuthAuditEvent(
            eventType: AuthAuditEventType::LoginSuccess,
            userId: 'msp|audit-user-id',
            authenticator: 'form',
            ipAddress: '203.0.113.7',
            userAgent: 'Mozilla/5.0 Test',
        ));

        $row = $this->fetchLastEventFor($account);

        self::assertSame('login_success', $row['event_type']);
        self::assertSame($account->email, $row['email']);
        self::assertSame('form', $row['authenticator']);
        self::assertSame('203.0.113.7', $row['ip_address']);
        self::assertSame('Mozilla/5.0 Test', $row['user_agent']);
    }

    public function testResolvesAccountFromEmailLookup(): void
    {
        $account = $this->createAccount('msp|audit-email-lookup');

        $this->messageBus->dispatch(new RecordAuthAuditEvent(
            eventType: AuthAuditEventType::LoginFailure,
            email: mb_strtoupper($account->email),
        ));

        $row = $this->fetchLastEventFor($account);

        self::assertSame('login_failure', $row['event_type']);
        self::assertSame($account->email, $row['email'], 'Attempted email must be stored lowercased');
    }

    public function testUnknownEmailIsRecordedWithoutAccount(): void
    {
        $email = sprintf('unknown+%s@example.com', bin2hex(random_bytes(4)));

        $this->messageBus->dispatch(new RecordAuthAuditEvent(
            eventType: AuthAuditEventType::LoginFailure,
            email: $email,
            metadata: ['reason' => 'BadCredentialsException'],
        ));

        /** @var false|array<string, mixed> $row */
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM auth_audit_log WHERE email = :email ORDER BY occurred_at DESC LIMIT 1',
            ['email' => $email],
        );

        self::assertNotFalse($row, 'Event must be recorded even without an account - lookup, never create');
        self::assertNull($row['user_account_id']);
        self::assertIsString($row['metadata']);
        self::assertSame(['reason' => 'BadCredentialsException'], json_decode($row['metadata'], associative: true));
    }

    public function testUserAgentIsTruncated(): void
    {
        $account = $this->createAccount('msp|audit-long-ua');

        $this->messageBus->dispatch(new RecordAuthAuditEvent(
            eventType: AuthAuditEventType::LoginSuccess,
            userId: 'msp|audit-long-ua',
            userAgent: str_repeat('x', 600),
        ));

        $row = $this->fetchLastEventFor($account);

        self::assertIsString($row['user_agent']);
        self::assertSame(500, mb_strlen($row['user_agent']));
    }

    private function createAccount(string $userId): UserAccount
    {
        $email = sprintf('audit+%s@example.com', bin2hex(random_bytes(4)));
        $userAccount = new UserAccount(Uuid::uuid7(), $userId, $email, new DateTimeImmutable());

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($userAccount);
        $entityManager->flush();

        return $userAccount;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchLastEventFor(UserAccount $account): array
    {
        /** @var false|array<string, mixed> $row */
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM auth_audit_log WHERE user_account_id = :id ORDER BY occurred_at DESC LIMIT 1',
            ['id' => $account->id->toString()],
        );

        self::assertNotFalse($row);

        return $row;
    }
}
