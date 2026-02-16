<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
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

    public function testCountByPuzzleIdsWithMultiplePuzzles(): void
    {
        $counts = $this->getSellSwapListItems->countByPuzzleIds([
            PuzzleFixture::PUZZLE_500_01,
            PuzzleFixture::PUZZLE_1000_01,
        ]);

        self::assertArrayHasKey(PuzzleFixture::PUZZLE_500_01, $counts);
        self::assertArrayHasKey(PuzzleFixture::PUZZLE_1000_01, $counts);
        self::assertGreaterThan(0, $counts[PuzzleFixture::PUZZLE_500_01]);
        self::assertGreaterThan(0, $counts[PuzzleFixture::PUZZLE_1000_01]);
    }

    public function testCountByPuzzleIdsWithSinglePuzzle(): void
    {
        $counts = $this->getSellSwapListItems->countByPuzzleIds([
            PuzzleFixture::PUZZLE_500_01,
        ]);

        self::assertArrayHasKey(PuzzleFixture::PUZZLE_500_01, $counts);
        // PUZZLE_500_01 has 2 offers: SELLSWAP_01 (PLAYER_WITH_STRIPE) and SELLSWAP_10 (PLAYER_ADMIN)
        self::assertSame(2, $counts[PuzzleFixture::PUZZLE_500_01]);
    }

    public function testCountByPuzzleIdsExcludesPuzzlesWithoutOffers(): void
    {
        // PUZZLE_1000_04 has no sell/swap items in fixtures
        $counts = $this->getSellSwapListItems->countByPuzzleIds([
            PuzzleFixture::PUZZLE_500_01,
            PuzzleFixture::PUZZLE_1000_04,
        ]);

        self::assertArrayHasKey(PuzzleFixture::PUZZLE_500_01, $counts);
        self::assertArrayNotHasKey(PuzzleFixture::PUZZLE_1000_04, $counts);
        self::assertSame(0, $counts[PuzzleFixture::PUZZLE_1000_04] ?? 0);
    }

    public function testCountByPuzzleIdsWithEmptyArrayReturnsEmpty(): void
    {
        $counts = $this->getSellSwapListItems->countByPuzzleIds([]);

        self::assertSame([], $counts);
    }
}
