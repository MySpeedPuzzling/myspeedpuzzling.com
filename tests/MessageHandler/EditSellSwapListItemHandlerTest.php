<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\SellSwapListItemNotFound;
use SpeedPuzzling\Web\Message\EditSellSwapListItem;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class EditSellSwapListItemHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private SellSwapListItemRepository $sellSwapListItemRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->sellSwapListItemRepository = $container->get(SellSwapListItemRepository::class);
    }

    public function testEditingAllFields(): void
    {
        // SELLSWAP_01 belongs to PLAYER_WITH_STRIPE, is Sell, 25.00, LikeNew
        $this->messageBus->dispatch(
            new EditSellSwapListItem(
                sellSwapListItemId: SellSwapListItemFixture::SELLSWAP_01,
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
                listingType: ListingType::Both,
                price: 30.00,
                condition: PuzzleCondition::Normal,
                comment: 'Updated comment',
                publishedOnMarketplace: false,
            ),
        );

        $item = $this->sellSwapListItemRepository->get(SellSwapListItemFixture::SELLSWAP_01);
        self::assertSame(ListingType::Both, $item->listingType);
        self::assertSame(30.00, $item->price);
        self::assertSame(PuzzleCondition::Normal, $item->condition);
        self::assertSame('Updated comment', $item->comment);
        self::assertFalse($item->publishedOnMarketplace);
    }

    public function testNonOwnerCannotEdit(): void
    {
        try {
            $this->messageBus->dispatch(
                new EditSellSwapListItem(
                    sellSwapListItemId: SellSwapListItemFixture::SELLSWAP_01,
                    playerId: PlayerFixture::PLAYER_ADMIN,
                    listingType: ListingType::Swap,
                    price: null,
                    condition: PuzzleCondition::MissingPieces,
                    comment: 'Hacked',
                ),
            );
            self::fail('Expected SellSwapListItemNotFound exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(SellSwapListItemNotFound::class, $previous);
        }
    }
}
