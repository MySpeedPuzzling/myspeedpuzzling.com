<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Query\GetAuthAuditEvents;
use SpeedPuzzling\Web\Value\AuthAuditEventType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetAuthAuditEventsTest extends KernelTestCase
{
    private GetAuthAuditEvents $query;
    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetAuthAuditEvents::class);
        $this->connection = $container->get(Connection::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testReturnsNewestFirstFilteredAndLimited(): void
    {
        $account = $this->createAccount();

        $this->insertEvent($account, 'login_success', new DateTimeImmutable('-3 hours'));
        $this->insertEvent($account, 'login_failure', new DateTimeImmutable('-2 hours'));
        $this->insertEvent($account, 'password_changed', new DateTimeImmutable('-1 hour'));
        // Not user-meaningful - filtered out
        $this->insertEvent($account, 'logout', new DateTimeImmutable('-30 minutes'));
        $this->insertEvent($account, 'sign_in_link_requested', new DateTimeImmutable('-15 minutes'));

        $events = $this->query->recentForUserAccount($account->id->toString());

        self::assertCount(3, $events);
        self::assertSame(AuthAuditEventType::PasswordChanged, $events[0]->eventType);
        self::assertSame(AuthAuditEventType::LoginFailure, $events[1]->eventType);
        self::assertSame(AuthAuditEventType::LoginSuccess, $events[2]->eventType);

        self::assertSame('203.0.113.5', $events[0]->ipAddress);
        self::assertSame('Chrome on Windows', $events[0]->deviceLabel);
    }

    public function testLimitIsApplied(): void
    {
        $account = $this->createAccount();

        for ($i = 0; $i < 5; $i++) {
            $this->insertEvent($account, 'login_success', new DateTimeImmutable("-{$i} hours"));
        }

        self::assertCount(2, $this->query->recentForUserAccount($account->id->toString(), limit: 2));
    }

    private function createAccount(): UserAccount
    {
        $email = sprintf('query+%s@example.com', bin2hex(random_bytes(4)));
        $userAccount = new UserAccount(Uuid::uuid7(), 'msp|' . bin2hex(random_bytes(4)), $email, new DateTimeImmutable());

        $this->entityManager->persist($userAccount);
        $this->entityManager->flush();

        return $userAccount;
    }

    private function insertEvent(UserAccount $account, string $eventType, DateTimeImmutable $occurredAt): void
    {
        $this->connection->insert('auth_audit_log', [
            'id' => Uuid::uuid7()->toString(),
            'user_account_id' => $account->id->toString(),
            'email' => $account->email,
            'event_type' => $eventType,
            'ip_address' => '203.0.113.5',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            'occurred_at' => $occurredAt->format('Y-m-d H:i:sP'),
        ]);
    }
}
