<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetSellSwapListItemsTest extends KernelTestCase
{
    private GetSellSwapListItems $getSellSwapListItems;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->getSellSwapListItems = $container->get(GetSellSwapListItems::class);
    }

    public function testReservedStatusIsIncludedInByPlayerId(): void
    {
        $items = $this->getSellSwapListItems->byPlayerId(PlayerFixture::PLAYER_WITH_STRIPE);

        $reservedItems = array_filter($items, static fn ($item) => $item->reserved);
        $nonReservedItems = array_filter($items, static fn ($item) => !$item->reserved);

        self::assertNotEmpty($reservedItems);
        self::assertNotEmpty($nonReservedItems);
    }

    public function testReservedStatusIsIncludedInByPuzzleId(): void
    {
        // PUZZLE_1000_01 has SELLSWAP_03 which is reserved
        $offers = $this->getSellSwapListItems->byPuzzleId(PuzzleFixture::PUZZLE_1000_01);

        self::assertNotEmpty($offers);

        $reservedOffer = null;
        foreach ($offers as $offer) {
            if ($offer->sellSwapListItemId === SellSwapListItemFixture::SELLSWAP_03) {
                $reservedOffer = $offer;
                break;
            }
        }

        self::assertNotNull($reservedOffer);
        self::assertTrue($reservedOffer->reserved);
    }
}
