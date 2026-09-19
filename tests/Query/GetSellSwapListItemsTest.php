<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
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
        // PUZZLE_500_01 has 2 items but SELLSWAP_10 has published_on_marketplace=false, so only 1 counts
        self::assertSame(1, $counts[PuzzleFixture::PUZZLE_500_01]);
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

    public function testCountByPuzzleIdExcludesUnpublishedItems(): void
    {
        // PUZZLE_500_01 has 2 items but SELLSWAP_10 has published_on_marketplace=false
        $count = $this->getSellSwapListItems->countByPuzzleId(PuzzleFixture::PUZZLE_500_01);

        self::assertSame(1, $count);
    }

    public function testCountByPuzzleIdsWithEmptyArrayReturnsEmpty(): void
    {
        $counts = $this->getSellSwapListItems->countByPuzzleIds([]);

        self::assertSame([], $counts);
    }

    public function testMarketplaceOffersByPuzzleIdReturnsPricedIsoCurrencyOffers(): void
    {
        // PUZZLE_500_01: SELLSWAP_01 (25.00, seller with GBP) qualifies, SELLSWAP_10 is unpublished
        $offers = $this->getSellSwapListItems->marketplaceOffersByPuzzleId(PuzzleFixture::PUZZLE_500_01);

        self::assertCount(1, $offers);
        self::assertSame(25.0, $offers[0]->price);
        self::assertSame('GBP', $offers[0]->currency);
        self::assertSame('https://schema.org/UsedCondition', $offers[0]->condition->toSchemaOrgCondition());
    }

    public function testMarketplaceOffersByPuzzleIdExcludesCustomCurrencySellers(): void
    {
        // PUZZLE_1500_01: SELLSWAP_06 (60.00, GBP seller) qualifies, SELLSWAP_13 seller uses custom currency
        $offers = $this->getSellSwapListItems->marketplaceOffersByPuzzleId(PuzzleFixture::PUZZLE_1500_01);

        self::assertCount(1, $offers);
        self::assertSame(60.0, $offers[0]->price);
        self::assertSame('https://schema.org/DamagedCondition', $offers[0]->condition->toSchemaOrgCondition());
    }

    public function testMarketplaceOffersByPuzzleIdExcludesReservedAndUnpriced(): void
    {
        // PUZZLE_1000_01: SELLSWAP_03 is reserved, SELLSWAP_11 seller has custom currency
        self::assertSame([], $this->getSellSwapListItems->marketplaceOffersByPuzzleId(PuzzleFixture::PUZZLE_1000_01));

        // PUZZLE_500_02: swap-only listing without price
        self::assertSame([], $this->getSellSwapListItems->marketplaceOffersByPuzzleId(PuzzleFixture::PUZZLE_500_02));
    }

    public function testMarketplaceOffersOfABlockedSellerDisappearForTheBlockerOnly(): void
    {
        // PUZZLE_500_01: the one qualifying offer (SELLSWAP_01) is by PLAYER_WITH_STRIPE
        $this->block(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertCount(1, $this->getSellSwapListItems->marketplaceOffersByPuzzleId(PuzzleFixture::PUZZLE_500_01));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);
        self::assertCount(1, $this->getSellSwapListItems->marketplaceOffersByPuzzleId(PuzzleFixture::PUZZLE_500_01));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);
        self::assertSame([], $this->getSellSwapListItems->marketplaceOffersByPuzzleId(PuzzleFixture::PUZZLE_500_01));
    }

    private function block(string $blockerId, string $blockedId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
