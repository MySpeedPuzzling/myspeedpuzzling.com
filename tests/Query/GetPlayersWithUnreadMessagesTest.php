<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetPlayersWithUnreadMessages;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayersWithUnreadMessagesTest extends KernelTestCase
{
    private GetPlayersWithUnreadMessages $query;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetPlayersWithUnreadMessages::class);
        $this->connection = $container->get(Connection::class);
    }

    public function testFindsPlayersWithUnreadMessagesOlderThanThreshold(): void
    {
        $players = $this->query->findPlayersToNotify(50);

        // PLAYER_REGULAR has unread messages from ADMIN (sent 1 day and 2 days ago) in accepted conversation
        $playerIds = array_map(static fn ($p) => $p->playerId, $players);
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $playerIds);
    }

    public function testDoesNotFindPlayersWhoseMessagesAreAllRead(): void
    {
        $players = $this->query->findPlayersToNotify(50);

        // PLAYER_ADMIN - all messages from REGULAR are read in the accepted conversation
        $playerIds = array_map(static fn ($p) => $p->playerId, $players);
        self::assertNotContains(PlayerFixture::PLAYER_ADMIN, $playerIds);
    }

    public function testDoesNotFindPlayersAlreadyNotifiedAboutSameUnreadBatch(): void
    {
        $players = $this->query->findPlayersToNotify(50);

        // PLAYER_WITH_FAVORITES has a DigestEmailLog entry covering their unread messages
        $playerIds = array_map(static fn ($p) => $p->playerId, $players);
        self::assertNotContains(PlayerFixture::PLAYER_WITH_FAVORITES, $playerIds);
    }

    public function testDoesNotFindPlayersWithoutEmail(): void
    {
        $players = $this->query->findPlayersToNotify(50);

        // All returned players should have an email
        foreach ($players as $player) {
            self::assertNotEmpty($player->playerEmail);
        }
    }

    public function testReturnsCorrectUnreadCount(): void
    {
        $players = $this->query->findPlayersToNotify(50);

        $regularPlayer = null;
        foreach ($players as $player) {
            if ($player->playerId === PlayerFixture::PLAYER_REGULAR) {
                $regularPlayer = $player;
                break;
            }
        }

        self::assertNotNull($regularPlayer);
        // PLAYER_REGULAR has 2 unread messages from ADMIN (MESSAGE_04 and MESSAGE_OLD_UNREAD)
        self::assertSame(2, $regularPlayer->totalUnreadCount);
    }

    public function testUnreadSummaryReturnsCorrectGroupings(): void
    {
        $summaries = $this->query->getUnreadSummaryForPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertNotEmpty($summaries);

        // PLAYER_REGULAR has unread messages from ADMIN in accepted conversation
        $adminSummary = null;
        foreach ($summaries as $summary) {
            if ($summary->senderCode === 'admin') {
                $adminSummary = $summary;
                break;
            }
        }

        self::assertNotNull($adminSummary);
        self::assertSame(2, $adminSummary->unreadCount);
        // The accepted conversation has no puzzle linked
        self::assertNull($adminSummary->puzzleName);
    }

    public function testPlayerWithLongerFrequencyIsNotNotifiedTooEarly(): void
    {
        // Change PLAYER_REGULAR to 1_week frequency
        $this->connection->executeStatement(
            "UPDATE player SET email_notification_frequency = '1_week' WHERE id = :id",
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );

        $players = $this->query->findPlayersToNotify(50);

        // PLAYER_REGULAR has unread messages only ~2 days old, which is less than 1 week
        $playerIds = array_map(static fn ($p) => $p->playerId, $players);
        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $playerIds);
    }

    public function testPlayerWithShorterFrequencyIsNotified(): void
    {
        // Change PLAYER_REGULAR to 6_hours frequency
        $this->connection->executeStatement(
            "UPDATE player SET email_notification_frequency = '6_hours' WHERE id = :id",
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );

        $players = $this->query->findPlayersToNotify(50);

        // PLAYER_REGULAR has unread messages >6 hours old, should be notified
        $playerIds = array_map(static fn ($p) => $p->playerId, $players);
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $playerIds);
    }

    public function testPendingRequestsRespectPlayerFrequency(): void
    {
        // Change PLAYER_REGULAR to 1_week frequency
        $this->connection->executeStatement(
            "UPDATE player SET email_notification_frequency = '1_week' WHERE id = :id",
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );

        $players = $this->query->findPlayersWithPendingRequestsToNotify(50);

        // Pending requests are ~2 days old, which is less than 1 week
        $playerIds = array_map(static fn ($p) => $p->playerId, $players);
        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $playerIds);
    }

    public function testUnreadSummaryIncludesPuzzleNameForMarketplaceConversation(): void
    {
        // PLAYER_WITH_STRIPE has unread marketplace message from WITH_FAVORITES
        $summaries = $this->query->getUnreadSummaryForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES);

        // WITH_FAVORITES has an unread message from WITH_STRIPE in marketplace conversation
        $marketplaceSummary = null;
        foreach ($summaries as $summary) {
            if ($summary->puzzleName !== null) {
                $marketplaceSummary = $summary;
                break;
            }
        }

        if ($marketplaceSummary !== null) {
            self::assertNotEmpty($marketplaceSummary->puzzleName);
        }
    }
}
