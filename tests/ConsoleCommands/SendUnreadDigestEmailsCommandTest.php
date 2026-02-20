<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\ConsoleCommands;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SendUnreadDigestEmailsCommandTest extends KernelTestCase
{
    private CommandTester $commandTester;
    private Connection $connection;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $command = $application->find('myspeedpuzzling:send-unread-digest-emails');
        $this->commandTester = new CommandTester($command);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testCommandRunsSuccessfully(): void
    {
        $this->commandTester->execute([]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Dispatched', $this->commandTester->getDisplay());
    }

    public function testCommandDispatchesForEligiblePlayers(): void
    {
        $this->commandTester->execute([]);

        $output = $this->commandTester->getDisplay();
        self::assertStringContainsString('Dispatched 1 digest email', $output);
    }

    public function testCommandIsIdempotent(): void
    {
        // First run
        $this->commandTester->execute([]);
        self::assertSame(0, $this->commandTester->getStatusCode());

        // Second run should not send duplicate emails
        $this->commandTester->execute([]);
        $secondOutput = $this->commandTester->getDisplay();
        self::assertSame(0, $this->commandTester->getStatusCode());

        // Second run should send 0 notifications since all players were already notified
        self::assertStringContainsString('No players to notify', $secondOutput);
    }

    public function testDuplicateEmailAddressReceivesOnlyOneNotification(): void
    {
        // Create a second player with the same email as PLAYER_REGULAR
        $duplicatePlayerId = Uuid::uuid7()->toString();
        $this->connection->executeStatement(
            "INSERT INTO player (id, code, user_id, email, name, email_notifications_enabled, email_notification_frequency, newsletter_enabled, registered_at, is_private, is_admin, messaging_muted, marketplace_banned, puzzle_collection_visibility, unsolved_puzzles_visibility, wish_list_visibility, lend_borrow_list_visibility, solved_puzzles_visibility, allow_direct_messages, rating_count, modal_displayed, favorite_players)
            VALUES (:id, :code, :user_id, :email, :name, true, '24_hours', true, :registered_at, false, false, false, false, 'private', 'private', 'private', 'private', 'private', true, 0, false, '[]')",
            [
                'id' => $duplicatePlayerId,
                'code' => 'duplicate_player',
                'user_id' => 'auth0|duplicate_' . substr($duplicatePlayerId, 0, 8),
                'email' => PlayerFixture::PLAYER_REGULAR_EMAIL,
                'name' => 'Duplicate Player',
                'registered_at' => (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s'),
            ],
        );

        // Create a pending conversation where duplicate player is the recipient
        $conversationId = Uuid::uuid7()->toString();
        $this->connection->insert('conversation', [
            'id' => $conversationId,
            'initiator_id' => PlayerFixture::PLAYER_ADMIN,
            'recipient_id' => $duplicatePlayerId,
            'status' => 'pending',
            'created_at' => (new \DateTimeImmutable('-3 days'))->format('Y-m-d H:i:s'),
            'last_message_at' => (new \DateTimeImmutable('-3 days'))->format('Y-m-d H:i:s'),
        ]);

        // Create message in the pending conversation
        $this->connection->insert('chat_message', [
            'id' => Uuid::uuid7()->toString(),
            'conversation_id' => $conversationId,
            'sender_id' => PlayerFixture::PLAYER_ADMIN,
            'content' => 'Hello duplicate!',
            'sent_at' => (new \DateTimeImmutable('-3 days'))->format('Y-m-d H:i:s'),
        ]);

        $this->commandTester->execute([]);
        $output = $this->commandTester->getDisplay();

        // Should send only 1 email even though 2 players share the same email
        self::assertStringContainsString('Dispatched 1 digest email', $output);
    }

    public function testDigestEmailLogIsCreatedAfterSending(): void
    {
        /** @var int $logsBefore */
        $logsBefore = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM digest_email_log WHERE player_id = :id',
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );

        $this->commandTester->execute([]);

        /** @var int $logsAfter */
        $logsAfter = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM digest_email_log WHERE player_id = :id',
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );

        // PLAYER_REGULAR has both unread messages and pending requests
        self::assertSame($logsBefore + 1, $logsAfter);
    }

    public function testPlayerNotifiedRecentlyIsNotNotifiedAgain(): void
    {
        // First run sends notification and creates log
        $this->commandTester->execute([]);
        $firstOutput = $this->commandTester->getDisplay();
        self::assertStringContainsString('Dispatched 1', $firstOutput);

        // Simulate a new unread message arriving after the first notification
        // (but still within 24h cooldown period)
        $this->connection->insert('chat_message', [
            'id' => Uuid::uuid7()->toString(),
            'conversation_id' => '018d000e-0000-0000-0000-000000000001', // CONVERSATION_ACCEPTED
            'sender_id' => PlayerFixture::PLAYER_ADMIN,
            'content' => 'New message after notification',
            'sent_at' => (new \DateTimeImmutable('-37 hours'))->format('Y-m-d H:i:s'),
        ]);

        // Second run should still not notify due to 24h cooldown
        $this->commandTester->execute([]);
        $secondOutput = $this->commandTester->getDisplay();
        self::assertStringContainsString('No players to notify', $secondOutput);
    }

    public function testPlayerWithNotificationsDisabledIsSkipped(): void
    {
        // Disable notifications for PLAYER_REGULAR
        $this->connection->executeStatement(
            'UPDATE player SET email_notifications_enabled = false WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );

        $this->commandTester->execute([]);
        $output = $this->commandTester->getDisplay();

        self::assertStringContainsString('No players to notify', $output);
    }

    public function testPlayerWithWeeklyFrequencyIsNotNotifiedTooEarly(): void
    {
        // Change PLAYER_REGULAR to 1_week frequency
        $this->connection->executeStatement(
            "UPDATE player SET email_notification_frequency = '1_week' WHERE id = :id",
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );

        $this->commandTester->execute([]);
        $output = $this->commandTester->getDisplay();

        // PLAYER_REGULAR has unread messages only ~2 days old, which is less than 1 week
        self::assertStringContainsString('No players to notify', $output);
    }
}
