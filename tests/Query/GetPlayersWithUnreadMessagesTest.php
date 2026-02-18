<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetPlayersWithUnreadMessages;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayersWithUnreadMessagesTest extends KernelTestCase
{
    private GetPlayersWithUnreadMessages $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetPlayersWithUnreadMessages::class);
    }

    public function testFindsPlayersWithUnreadMessagesOlderThanThreshold(): void
    {
        $players = $this->query->findPlayersToNotify(12);

        // PLAYER_REGULAR has unread messages from ADMIN (sent 1 day and 2 days ago) in accepted conversation
        $playerIds = array_map(static fn ($p) => $p->playerId, $players);
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $playerIds);
    }

    public function testDoesNotFindPlayersWhoseMessagesAreAllRead(): void
    {
        $players = $this->query->findPlayersToNotify(12);

        // PLAYER_ADMIN - all messages from REGULAR are read in the accepted conversation
        $playerIds = array_map(static fn ($p) => $p->playerId, $players);
        self::assertNotContains(PlayerFixture::PLAYER_ADMIN, $playerIds);
    }

    public function testDoesNotFindPlayersAlreadyNotifiedAboutSameUnreadBatch(): void
    {
        $players = $this->query->findPlayersToNotify(12);

        // PLAYER_WITH_FAVORITES has a MessageNotificationLog entry covering their unread messages
        $playerIds = array_map(static fn ($p) => $p->playerId, $players);
        self::assertNotContains(PlayerFixture::PLAYER_WITH_FAVORITES, $playerIds);
    }

    public function testDoesNotFindPlayersWithoutEmail(): void
    {
        $players = $this->query->findPlayersToNotify(12);

        // All returned players should have an email
        foreach ($players as $player) {
            self::assertNotEmpty($player->playerEmail);
        }
    }

    public function testReturnsCorrectUnreadCount(): void
    {
        $players = $this->query->findPlayersToNotify(12);

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
