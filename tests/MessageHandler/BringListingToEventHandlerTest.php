<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\CompetitionNotEligibleForMarketplace;
use SpeedPuzzling\Web\Exceptions\MarketplaceBanned;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotGoingToCompetition;
use SpeedPuzzling\Web\Exceptions\SellSwapListItemNotFound;
use SpeedPuzzling\Web\Message\BringListingToEvent;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Seller A (PLAYER_WITH_STRIPE) goes to the fair (brings SELLSWAP_01 + 02) and to EDITION_OFFLINE_1 (nothing marked);
 * CONVERSATION_MARKETPLACE is PLAYER_WITH_FAVORITES asking A about SELLSWAP_01.
 */
final class BringListingToEventHandlerTest extends KernelTestCase
{
    private const string SELLER_A = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string BUYER = PlayerFixture::PLAYER_WITH_FAVORITES;

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testBringingCreatesTheLink(): void
    {
        $this->bring(SellSwapListItemFixture::SELLSWAP_03, CompetitionSeriesFixture::EDITION_OFFLINE_1);

        self::assertTrue($this->isLinked(SellSwapListItemFixture::SELLSWAP_03, CompetitionSeriesFixture::EDITION_OFFLINE_1));
        self::assertFalse($this->isLinked(SellSwapListItemFixture::SELLSWAP_03, MarketplaceEventFixture::COMPETITION_SWAP_FAIR));
        self::assertSame(0, $this->systemMessagesIn(ConversationFixture::CONVERSATION_MARKETPLACE));
    }

    public function testBringingTwiceIsIdempotent(): void
    {
        $before = $this->addedAt(SellSwapListItemFixture::SELLSWAP_01, MarketplaceEventFixture::COMPETITION_SWAP_FAIR);

        $this->bring(SellSwapListItemFixture::SELLSWAP_01, MarketplaceEventFixture::COMPETITION_SWAP_FAIR);
        $this->bring(SellSwapListItemFixture::SELLSWAP_01, MarketplaceEventFixture::COMPETITION_SWAP_FAIR);

        self::assertSame($before, $this->addedAt(SellSwapListItemFixture::SELLSWAP_01, MarketplaceEventFixture::COMPETITION_SWAP_FAIR));
    }

    public function testBringAndReserveReservesLikeTheReserveAction(): void
    {
        $this->bring(SellSwapListItemFixture::SELLSWAP_01, CompetitionSeriesFixture::EDITION_OFFLINE_1, self::BUYER);

        self::assertTrue($this->isLinked(SellSwapListItemFixture::SELLSWAP_01, CompetitionSeriesFixture::EDITION_OFFLINE_1));

        $item = self::getContainer()->get(SellSwapListItemRepository::class)->get(SellSwapListItemFixture::SELLSWAP_01);
        self::assertTrue($item->reserved);
        self::assertNotNull($item->reservedAt);
        self::assertSame(self::BUYER, $item->reservedForPlayerId?->toString());

        // The same system message MarkListingAsReservedHandler sends, once
        self::assertSame(1, $this->systemMessagesIn(ConversationFixture::CONVERSATION_MARKETPLACE, 'listing_reserved'));

        // A second click: already reserved for that buyer - no second system message
        $this->bring(SellSwapListItemFixture::SELLSWAP_01, MarketplaceEventFixture::COMPETITION_SWAP_FAIR, self::BUYER);
        self::assertSame(1, $this->systemMessagesIn(ConversationFixture::CONVERSATION_MARKETPLACE, 'listing_reserved'));
    }

    public function testOnlyAConversationPartnerCanGetTheListingReserved(): void
    {
        // PLAYER_ADMIN exists, but has no conversation with seller A about SELLSWAP_01
        foreach ([PlayerFixture::PLAYER_ADMIN, '018d0000-ffff-0000-0000-000000000001', 'not-a-uuid'] as $notAPartner) {
            try {
                $this->bring(SellSwapListItemFixture::SELLSWAP_01, CompetitionSeriesFixture::EDITION_OFFLINE_1, $notAPartner);
                self::fail('Expected PlayerNotFound for ' . $notAPartner);
            } catch (PlayerNotFound) {
            }
        }

        self::assertFalse($this->isLinked(SellSwapListItemFixture::SELLSWAP_01, CompetitionSeriesFixture::EDITION_OFFLINE_1));
        self::assertFalse(self::getContainer()->get(SellSwapListItemRepository::class)->get(SellSwapListItemFixture::SELLSWAP_01)->reserved);
        self::assertSame(0, $this->systemMessagesIn(ConversationFixture::CONVERSATION_MARKETPLACE));
    }

    public function testListingReservedForSomebodyElseKeepsItsReservation(): void
    {
        // Reserved for PLAYER_ADMIN in another tab after the menu was rendered
        $this->database->executeStatement(
            'UPDATE sell_swap_list_item SET reserved = true, reserved_at = NOW(), reserved_for_player_id = :admin WHERE id = :id',
            ['admin' => PlayerFixture::PLAYER_ADMIN, 'id' => SellSwapListItemFixture::SELLSWAP_01],
        );

        $this->bring(SellSwapListItemFixture::SELLSWAP_01, CompetitionSeriesFixture::EDITION_OFFLINE_1, self::BUYER);

        self::assertTrue($this->isLinked(SellSwapListItemFixture::SELLSWAP_01, CompetitionSeriesFixture::EDITION_OFFLINE_1));
        $item = self::getContainer()->get(SellSwapListItemRepository::class)->get(SellSwapListItemFixture::SELLSWAP_01);
        self::assertTrue($item->reserved);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $item->reservedForPlayerId?->toString());
        self::assertSame(0, $this->systemMessagesIn(ConversationFixture::CONVERSATION_MARKETPLACE));
    }

    public function testUnpublishedListingGetsNoLink(): void
    {
        $this->database->executeStatement(
            'UPDATE sell_swap_list_item SET published_on_marketplace = false WHERE id = :id',
            ['id' => SellSwapListItemFixture::SELLSWAP_06],
        );

        $this->bring(SellSwapListItemFixture::SELLSWAP_06, MarketplaceEventFixture::COMPETITION_SWAP_FAIR);

        self::assertFalse($this->isLinked(SellSwapListItemFixture::SELLSWAP_06, MarketplaceEventFixture::COMPETITION_SWAP_FAIR));
    }

    public function testSomeoneElsesListingIsRefused(): void
    {
        $this->expectException(SellSwapListItemNotFound::class);

        // SELLSWAP_08 is seller B's, B goes to the fair as well
        $this->bring(SellSwapListItemFixture::SELLSWAP_08, MarketplaceEventFixture::COMPETITION_SWAP_FAIR);
    }

    public function testEventTheSellerDoesNotGoToIsRefused(): void
    {
        $this->expectException(PlayerNotGoingToCompetition::class);

        // A marketplace event (WJPC 2024, +30 days) seller A is not going to
        $this->bring(SellSwapListItemFixture::SELLSWAP_03, CompetitionFixture::COMPETITION_WJPC_2024);
    }

    public function testPastEventIsRefused(): void
    {
        $this->expectException(CompetitionNotEligibleForMarketplace::class);

        $this->bring(SellSwapListItemFixture::SELLSWAP_03, CompetitionSeriesFixture::EDITION_PAST_ONLY_1);
    }

    public function testBannedSellerIsRefused(): void
    {
        $this->database->executeStatement('UPDATE player SET marketplace_banned = true WHERE id = :id', ['id' => self::SELLER_A]);

        try {
            $this->bring(SellSwapListItemFixture::SELLSWAP_03, MarketplaceEventFixture::COMPETITION_SWAP_FAIR);
            self::fail('Expected MarketplaceBanned');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(MarketplaceBanned::class, $exception->getPrevious());
        }

        self::assertFalse($this->isLinked(SellSwapListItemFixture::SELLSWAP_03, MarketplaceEventFixture::COMPETITION_SWAP_FAIR));
    }

    private function bring(string $listItemId, string $competitionId, null|string $reserveFor = null): void
    {
        $this->messageBus->dispatch(new BringListingToEvent(
            playerId: self::SELLER_A,
            listItemId: $listItemId,
            competitionId: $competitionId,
            reserveForPlayerId: $reserveFor,
        ));
    }

    private function isLinked(string $listItemId, string $competitionId): bool
    {
        return $this->addedAt($listItemId, $competitionId) !== null;
    }

    private function addedAt(string $listItemId, string $competitionId): null|string
    {
        $addedAt = $this->database->fetchOne(
            'SELECT added_at FROM sell_swap_list_item_event WHERE sell_swap_list_item_id = :item AND competition_id = :competition',
            ['item' => $listItemId, 'competition' => $competitionId],
        );

        return is_string($addedAt) ? $addedAt : null;
    }

    private function systemMessagesIn(string $conversationId, null|string $type = null): int
    {
        $count = $this->database->fetchOne(
            'SELECT COUNT(*) FROM chat_message WHERE conversation_id = :conversation AND system_message_type IS NOT NULL'
                . ($type !== null ? ' AND system_message_type = :type' : ''),
            ['conversation' => $conversationId] + ($type !== null ? ['type' => $type] : []),
        );
        assert(is_int($count));

        return $count;
    }
}
