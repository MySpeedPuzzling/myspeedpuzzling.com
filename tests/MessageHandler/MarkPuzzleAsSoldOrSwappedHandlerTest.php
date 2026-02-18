<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\SellSwapListItemNotFound;
use SpeedPuzzling\Web\Message\MarkPuzzleAsSoldOrSwapped;
use SpeedPuzzling\Web\Query\GetMessages;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Repository\SoldSwappedItemRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class MarkPuzzleAsSoldOrSwappedHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private SoldSwappedItemRepository $soldSwappedItemRepository;
    private GetSellSwapListItems $getSellSwapListItems;
    private GetMessages $getMessages;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->soldSwappedItemRepository = $container->get(SoldSwappedItemRepository::class);
        $this->getSellSwapListItems = $container->get(GetSellSwapListItems::class);
        $this->getMessages = $container->get(GetMessages::class);
    }

    public function testMarkingAsSoldCreatesHistoryAndDeletesListing(): void
    {
        // SELLSWAP_02 belongs to PLAYER_WITH_STRIPE, puzzle 500_02
        $countBefore = $this->getSellSwapListItems->countByPlayerId(PlayerFixture::PLAYER_WITH_STRIPE);

        $this->messageBus->dispatch(
            new MarkPuzzleAsSoldOrSwapped(
                sellSwapListItemId: SellSwapListItemFixture::SELLSWAP_02,
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
                buyerInput: null,
            ),
        );

        // Listing should be deleted
        $countAfter = $this->getSellSwapListItems->countByPlayerId(PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertSame($countBefore - 1, $countAfter);

        // History record should exist
        $soldItems = $this->soldSwappedItemRepository->findByPlayerId(PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertNotEmpty($soldItems);

        $lastSold = $soldItems[0];
        self::assertSame(PuzzleFixture::PUZZLE_500_02, $lastSold->puzzle->id->toString());
        self::assertNull($lastSold->buyerPlayer);
        self::assertNull($lastSold->buyerName);
    }

    public function testMarkingAsSoldWithRegisteredBuyerByCode(): void
    {
        $this->messageBus->dispatch(
            new MarkPuzzleAsSoldOrSwapped(
                sellSwapListItemId: SellSwapListItemFixture::SELLSWAP_06,
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
                buyerInput: '#admin',
            ),
        );

        $soldItems = $this->soldSwappedItemRepository->findByPlayerId(PlayerFixture::PLAYER_WITH_STRIPE);
        $lastSold = $soldItems[0];

        self::assertNotNull($lastSold->buyerPlayer);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $lastSold->buyerPlayer->id->toString());
        self::assertNull($lastSold->buyerName);
    }

    public function testMarkingAsSoldWithFreeTextBuyerName(): void
    {
        $this->messageBus->dispatch(
            new MarkPuzzleAsSoldOrSwapped(
                sellSwapListItemId: SellSwapListItemFixture::SELLSWAP_07,
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
                buyerInput: 'Jan Novak',
            ),
        );

        $soldItems = $this->soldSwappedItemRepository->findByPlayerId(PlayerFixture::PLAYER_WITH_STRIPE);
        $lastSold = $soldItems[0];

        self::assertNull($lastSold->buyerPlayer);
        self::assertSame('Jan Novak', $lastSold->buyerName);
    }

    public function testMarkingAsSoldWithInvalidPlayerCodeFallsBackToFreeText(): void
    {
        $this->messageBus->dispatch(
            new MarkPuzzleAsSoldOrSwapped(
                sellSwapListItemId: SellSwapListItemFixture::SELLSWAP_05,
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
                buyerInput: '#nonexistent',
            ),
        );

        $soldItems = $this->soldSwappedItemRepository->findByPlayerId(PlayerFixture::PLAYER_WITH_STRIPE);
        $lastSold = $soldItems[0];

        self::assertNull($lastSold->buyerPlayer);
        self::assertSame('nonexistent', $lastSold->buyerName);
    }

    public function testSystemMessageCreatedInConversationAfterSale(): void
    {
        // SELLSWAP_01 has a marketplace conversation (CONVERSATION_MARKETPLACE)
        $this->messageBus->dispatch(
            new MarkPuzzleAsSoldOrSwapped(
                sellSwapListItemId: SellSwapListItemFixture::SELLSWAP_01,
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
                buyerInput: null,
            ),
        );

        $messages = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_MARKETPLACE,
            PlayerFixture::PLAYER_WITH_FAVORITES,
        );

        $systemMessages = array_filter($messages, static fn ($m) => $m->isSystemMessage);
        self::assertNotEmpty($systemMessages);
    }

    public function testNonOwnerCannotMarkAsSold(): void
    {
        try {
            $this->messageBus->dispatch(
                new MarkPuzzleAsSoldOrSwapped(
                    sellSwapListItemId: SellSwapListItemFixture::SELLSWAP_03,
                    playerId: PlayerFixture::PLAYER_ADMIN,
                    buyerInput: null,
                ),
            );
            self::fail('Expected SellSwapListItemNotFound exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(SellSwapListItemNotFound::class, $previous);
        }
    }
}
