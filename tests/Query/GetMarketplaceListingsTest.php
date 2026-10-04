<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\SellSwapListItemNotFound;
use SpeedPuzzling\Web\Query\GetMarketplaceListings;
use SpeedPuzzling\Web\Results\MarketplaceListingItem;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetMarketplaceListingsTest extends KernelTestCase
{
    private GetMarketplaceListings $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetMarketplaceListings::class);
    }

    public function testBasicListingRetrieval(): void
    {
        $items = $this->query->search();

        self::assertNotEmpty($items);
    }

    public function testSearchByPuzzleName(): void
    {
        // PUZZLE_500_01 is "Puzzle 500-01" (or similar name from fixtures)
        // Search for a term that should match at least one puzzle
        $allItems = $this->query->search();
        self::assertNotEmpty($allItems);

        // Get the first item's name and search for part of it
        $firstItem = $allItems[0];
        $searchTerm = substr($firstItem->puzzleName, 0, 5);

        $items = $this->query->search(searchTerm: $searchTerm);
        self::assertNotEmpty($items);
    }

    public function testFilterByManufacturer(): void
    {
        $items = $this->query->search(manufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER);

        // SELLSWAP_01 (PUZZLE_500_01=Ravensburger), SELLSWAP_02, SELLSWAP_03, SELLSWAP_07 are Ravensburger
        self::assertNotEmpty($items);

        foreach ($items as $item) {
            self::assertSame('Ravensburger', $item->manufacturerName);
        }
    }

    public function testFilterByPiecesRange(): void
    {
        $items = $this->query->search(piecesMin: 1000, piecesMax: 1000);

        self::assertNotEmpty($items);

        foreach ($items as $item) {
            self::assertSame(1000, $item->piecesCount);
        }
    }

    public function testFilterByListingType(): void
    {
        $items = $this->query->search(listingType: ListingType::Swap);

        self::assertNotEmpty($items);

        foreach ($items as $item) {
            self::assertSame('swap', $item->listingType);
        }
    }

    public function testFilterByPriceRange(): void
    {
        $items = $this->query->search(priceMin: 20.0, priceMax: 30.0);

        self::assertNotEmpty($items);

        foreach ($items as $item) {
            self::assertNotNull($item->price);
            self::assertGreaterThanOrEqual(20.0, $item->price);
            self::assertLessThanOrEqual(30.0, $item->price);
        }
    }

    public function testFilterByCondition(): void
    {
        $items = $this->query->search(condition: PuzzleCondition::LikeNew);

        self::assertNotEmpty($items);

        foreach ($items as $item) {
            self::assertSame('like_new', $item->condition);
        }
    }

    public function testFilterByConditionNew(): void
    {
        $items = $this->query->search(condition: PuzzleCondition::New);

        self::assertNotEmpty($items);

        foreach ($items as $item) {
            self::assertSame('new', $item->condition);
        }
    }

    public function testSortByNewest(): void
    {
        $items = $this->query->search(sort: 'newest');

        self::assertNotEmpty($items);

        // Verify ordering: each item should have addedAt >= next item
        for ($i = 0; $i < count($items) - 1; $i++) {
            self::assertGreaterThanOrEqual($items[$i + 1]->addedAt, $items[$i]->addedAt);
        }
    }

    public function testSortByPriceAscending(): void
    {
        $items = $this->query->search(sort: 'price_asc');

        self::assertNotEmpty($items);

        // Filter to items with price (nulls last)
        $priced = array_filter($items, static fn ($item) => $item->price !== null);
        $pricedValues = array_values(array_map(static fn ($item) => $item->price, $priced));

        for ($i = 0; $i < count($pricedValues) - 1; $i++) {
            self::assertLessThanOrEqual($pricedValues[$i + 1], $pricedValues[$i]);
        }
    }

    public function testSortByPriceDescending(): void
    {
        $items = $this->query->search(sort: 'price_desc');

        self::assertNotEmpty($items);

        $priced = array_filter($items, static fn ($item) => $item->price !== null);
        $pricedValues = array_values(array_map(static fn ($item) => $item->price, $priced));

        for ($i = 0; $i < count($pricedValues) - 1; $i++) {
            self::assertGreaterThanOrEqual($pricedValues[$i + 1], $pricedValues[$i]);
        }
    }

    public function testPagination(): void
    {
        $allItems = $this->query->search(limit: 100);
        $totalCount = count($allItems);

        if ($totalCount <= 2) {
            self::markTestSkipped('Not enough items to test pagination');
        }

        $page1 = $this->query->search(limit: 2, offset: 0);
        $page2 = $this->query->search(limit: 2, offset: 2);

        self::assertCount(2, $page1);
        self::assertNotEmpty($page2);
        self::assertNotSame($page1[0]->itemId, $page2[0]->itemId);
    }

    public function testCountMatchesSearch(): void
    {
        $items = $this->query->search(listingType: ListingType::Sell);
        $count = $this->query->count(listingType: ListingType::Sell);

        self::assertSame(count($items), $count);
    }

    public function testFilterByDifficulty(): void
    {
        $all = $this->query->search(limit: 1000);
        $puzzleIds = array_values(array_unique(array_map(static fn (MarketplaceListingItem $item): string => $item->puzzleId, $all)));
        self::assertGreaterThanOrEqual(2, count($puzzleIds));

        // One listed puzzle is Hard, the others are not rated yet
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('DELETE FROM puzzle_difficulty');
        $connection->executeStatement(
            "INSERT INTO puzzle_difficulty (puzzle_id, difficulty_tier, difficulty_score, confidence, sample_size, computed_at) VALUES (:puzzleId, 5, 1.3, 'high', 10, NOW())",
            ['puzzleId' => $puzzleIds[0]],
        );

        $hard = $this->query->search(limit: 1000, difficultyTiers: [5]);
        self::assertNotEmpty($hard);
        self::assertSame([$puzzleIds[0]], array_values(array_unique(array_map(static fn (MarketplaceListingItem $item): string => $item->puzzleId, $hard))));
        self::assertSame(count($hard), $this->query->count(difficultyTiers: [5]));

        // 0 = not rated yet
        $unrated = $this->query->search(limit: 1000, difficultyTiers: [0]);
        self::assertCount(count($all) - count($hard), $unrated);
        self::assertNotContains($puzzleIds[0], array_map(static fn (MarketplaceListingItem $item): string => $item->puzzleId, $unrated));
        self::assertSame(count($unrated), $this->query->count(difficultyTiers: [0]));

        self::assertCount(count($all), $this->query->search(limit: 1000, difficultyTiers: [0, 5]));
        self::assertSame([], $this->query->search(limit: 1000, difficultyTiers: [1]));
        self::assertSame(0, $this->query->count(difficultyTiers: [1]));
    }

    public function testSortByDifficulty(): void
    {
        $all = $this->query->search(limit: 1000);
        $puzzleIds = array_values(array_unique(array_map(static fn (MarketplaceListingItem $item): string => $item->puzzleId, $all)));
        self::assertGreaterThanOrEqual(4, count($puzzleIds));

        // Three listed puzzles rated (Hard, Easy, Average), the others not yet
        $scores = [$puzzleIds[0] => 1.3, $puzzleIds[1] => 0.8, $puzzleIds[2] => 1.0];
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('DELETE FROM puzzle_difficulty');

        foreach ($scores as $puzzleId => $score) {
            $connection->executeStatement(
                "INSERT INTO puzzle_difficulty (puzzle_id, difficulty_tier, difficulty_score, confidence, sample_size, computed_at) VALUES (:puzzleId, :tier, :score, 'high', 10, NOW())",
                ['puzzleId' => $puzzleId, 'tier' => DifficultyTier::fromScore($score)->value, 'score' => $score],
            );
        }

        $easiest = $this->query->search(sort: 'easiest', limit: 1000);
        self::assertCount(count($all), $easiest);
        self::assertSame([0.8, 1.0, 1.3], self::distinctScores($easiest, $scores));
        self::assertDifficultySorted($easiest, $scores, hardestFirst: false);

        $hardest = $this->query->search(sort: 'hardest', limit: 1000);
        self::assertSame([1.3, 1.0, 0.8], self::distinctScores($hardest, $scores));
        self::assertDifficultySorted($hardest, $scores, hardestFirst: true);

        // Pages follow the same order
        $pages = [...$this->query->search(sort: 'hardest', limit: 2), ...$this->query->search(sort: 'hardest', limit: 2, offset: 2)];
        self::assertSame(self::itemIds(array_slice($hardest, 0, 4)), self::itemIds($pages));

        // Together with the difficulty filter, which joins the same table
        $easyOrHard = $this->query->search(sort: 'hardest', limit: 1000, difficultyTiers: [DifficultyTier::Easy->value, DifficultyTier::Hard->value]);
        self::assertSame([1.3, 0.8], self::distinctScores($easyOrHard, $scores));

        $averageOrUnrated = $this->query->search(sort: 'easiest', limit: 1000, difficultyTiers: [DifficultyTier::Average->value, 0]);
        self::assertSame([1.0], self::distinctScores($averageOrUnrated, $scores));
        self::assertDifficultySorted($averageOrUnrated, $scores, hardestFirst: false);
        self::assertSame(count($averageOrUnrated), $this->query->count(difficultyTiers: [DifficultyTier::Average->value, 0]));
    }

    /**
     * Rated first in score order, not rated yet last; equally difficult listings newest first.
     *
     * @param array<MarketplaceListingItem> $items
     * @param array<string, float> $scores
     */
    private static function assertDifficultySorted(array $items, array $scores, bool $hardestFirst): void
    {
        for ($i = 0; $i < count($items) - 1; $i++) {
            $a = $scores[$items[$i]->puzzleId] ?? null;
            $b = $scores[$items[$i + 1]->puzzleId] ?? null;

            if ($a === null) {
                self::assertNull($b, 'Not rated yet comes last');
            } elseif ($b !== null && $a !== $b) {
                $hardestFirst ? self::assertGreaterThan($b, $a) : self::assertLessThan($b, $a);
            }

            if ($a === $b) {
                self::assertGreaterThanOrEqual($items[$i + 1]->addedAt, $items[$i]->addedAt);
            }
        }
    }

    /**
     * @param array<MarketplaceListingItem> $items
     * @param array<string, float> $scores
     * @return list<float> the rated listings' scores in their order, once each
     */
    private static function distinctScores(array $items, array $scores): array
    {
        $listed = array_map(static fn (MarketplaceListingItem $item): null|float => $scores[$item->puzzleId] ?? null, $items);

        return array_values(array_unique(array_filter($listed, static fn (null|float $score): bool => $score !== null), SORT_REGULAR));
    }

    /**
     * @param array<MarketplaceListingItem> $items
     * @return list<string>
     */
    private static function itemIds(array $items): array
    {
        return array_values(array_map(static fn (MarketplaceListingItem $item): string => $item->itemId, $items));
    }

    public function testEmptyResultWithNonMatchingFilters(): void
    {
        $items = $this->query->search(piecesMin: 999999);

        self::assertEmpty($items);
    }

    public function testReservedItemsAreIncluded(): void
    {
        $items = $this->query->search();

        $reservedItems = array_filter($items, static fn ($item) => $item->reserved);
        $nonReservedItems = array_filter($items, static fn ($item) => !$item->reserved);

        // SELLSWAP_03 and SELLSWAP_04 are reserved
        self::assertNotEmpty($reservedItems);
        self::assertNotEmpty($nonReservedItems);
    }

    public function testGetManufacturersWithActiveListings(): void
    {
        $manufacturers = $this->query->getManufacturersWithActiveListings();

        self::assertNotEmpty($manufacturers);

        foreach ($manufacturers as $mfr) {
            self::assertNotEmpty($mfr['manufacturer_id']);
            self::assertNotEmpty($mfr['manufacturer_name']);
            self::assertGreaterThan(0, $mfr['listing_count']);
        }
    }

    public function testCountWithNoFilters(): void
    {
        $count = $this->query->count();
        $items = $this->query->search(limit: 100);

        self::assertSame(count($items), $count);
    }

    public function testSearchByEan(): void
    {
        // PUZZLE_500_02 has EAN 4005556123456
        $items = $this->query->search(searchTerm: '4005556123456');

        // This puzzle has SELLSWAP_02
        self::assertNotEmpty($items);
    }

    public function testPuzzleWithMultipleOffers(): void
    {
        // PUZZLE_1000_01 has 2 published offers from different sellers (SELLSWAP_03 and SELLSWAP_11)
        $allItems = $this->query->search(limit: 100);

        $puzzle1000_01Items = array_filter(
            $allItems,
            static fn ($item) => $item->puzzleId === PuzzleFixture::PUZZLE_1000_01,
        );

        self::assertCount(2, $puzzle1000_01Items);

        $sellerIds = array_map(static fn ($item) => $item->sellerId, $puzzle1000_01Items);
        self::assertCount(2, array_unique($sellerIds));
    }

    public function testUnpublishedItemsAreExcludedFromSearch(): void
    {
        // PUZZLE_500_01 has 2 items but SELLSWAP_10 has published_on_marketplace=false
        $allItems = $this->query->search(limit: 100);

        $puzzle500_01Items = array_filter(
            $allItems,
            static fn ($item) => $item->puzzleId === PuzzleFixture::PUZZLE_500_01,
        );

        self::assertCount(1, $puzzle500_01Items);
    }

    public function testPuzzleWithOnlyReservedOffers(): void
    {
        // PUZZLE_1000_02 ("Puzzle 7") has 2 offers, both reserved
        $allItems = $this->query->search(limit: 100);

        $puzzle1000_02Items = array_filter(
            $allItems,
            static fn ($item) => $item->puzzleId === PuzzleFixture::PUZZLE_1000_02,
        );

        self::assertCount(2, $puzzle1000_02Items);

        foreach ($puzzle1000_02Items as $item) {
            self::assertTrue($item->reserved);
        }
    }

    public function testPuzzleWithMixedReservationStatus(): void
    {
        // PUZZLE_1000_01 ("Puzzle 6") has 2 offers: one reserved, one not
        $allItems = $this->query->search(limit: 100);

        $puzzle1000_01Items = array_filter(
            $allItems,
            static fn ($item) => $item->puzzleId === PuzzleFixture::PUZZLE_1000_01,
        );

        self::assertCount(2, $puzzle1000_01Items);

        $reserved = array_filter($puzzle1000_01Items, static fn ($item) => $item->reserved);
        $nonReserved = array_filter($puzzle1000_01Items, static fn ($item) => !$item->reserved);

        self::assertCount(1, $reserved);
        self::assertCount(1, $nonReserved);
    }

    public function testFilterByShippingCountryReducesResults(): void
    {
        // player5 ships to gb, cz, de — player3 ships to cz, sk
        // Filtering for "sk" should return only player3's items, reducing total results
        $skItems = $this->query->search(shipsToCountry: 'sk');

        self::assertNotEmpty($skItems);

        $allItems = $this->query->search(limit: 100);
        self::assertGreaterThan(count($skItems), count($allItems), 'Shipping filter should reduce results');

        foreach ($skItems as $item) {
            self::assertSame(PlayerFixture::PLAYER_ADMIN, $item->sellerId);
        }
    }

    public function testFilterByShippingCountryReturnsOnlyMatchingSellers(): void
    {
        // player5 ships to gb, cz, de — player3 ships to cz, sk
        // Filtering for "gb" should return only player5's items
        $gbItems = $this->query->search(shipsToCountry: 'gb');

        self::assertNotEmpty($gbItems);

        foreach ($gbItems as $item) {
            self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $item->sellerId);
        }
    }

    public function testFilterByShippingCountryNoResults(): void
    {
        // No seller ships to "jp"
        $items = $this->query->search(shipsToCountry: 'jp');

        self::assertEmpty($items);
    }

    public function testCountWithShippingCountryFilter(): void
    {
        $czItems = $this->query->search(shipsToCountry: 'cz', limit: 100);
        $czCount = $this->query->count(shipsToCountry: 'cz');

        self::assertSame(count($czItems), $czCount);
    }

    public function testListingsOfABlockedSellerDisappearForTheBlockerAndTheCountFollows(): void
    {
        $this->block(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN);

        $everyone = $this->sellerIds();
        $ofBlockedSeller = count(array_keys($everyone, PlayerFixture::PLAYER_ADMIN, true));
        self::assertGreaterThan(0, $ofBlockedSeller);
        self::assertSame(count($everyone), $this->query->count());

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);
        $visible = $this->sellerIds();
        self::assertNotContains(PlayerFixture::PLAYER_ADMIN, $visible);
        self::assertCount(count($everyone) - $ofBlockedSeller, $visible);
        self::assertSame(count($visible), $this->query->count());
        self::assertSame([], $this->query->search(sellerId: PlayerFixture::PLAYER_ADMIN));
        self::assertSame(0, $this->query->count(sellerId: PlayerFixture::PLAYER_ADMIN));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertSame(count($everyone), $this->query->count());
        self::assertCount(count($everyone), $this->sellerIds());
    }

    public function testListingOfABlockedSellerIsNotFoundForTheBlocker(): void
    {
        $this->block(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN);

        self::assertSame(PlayerFixture::PLAYER_ADMIN, $this->query->byItemId(SellSwapListItemFixture::SELLSWAP_08)->sellerId);

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);
        self::assertSame(SellSwapListItemFixture::SELLSWAP_01, $this->query->byItemId(SellSwapListItemFixture::SELLSWAP_01)->itemId);

        $this->expectException(SellSwapListItemNotFound::class);
        $this->query->byItemId(SellSwapListItemFixture::SELLSWAP_08);
    }

    public function testShortNumberDoesNotMatchCodesAsAPart(): void
    {
        // Listed PUZZLE_1000_01 carries the brand code RB-1000-001, listed PUZZLE_500_02 the EAN 4005556123456
        self::assertSame([], $this->query->search(searchTerm: '1000'));
        self::assertSame(0, $this->query->count(searchTerm: '1000'));

        $this->updatePuzzle(PuzzleFixture::PUZZLE_500_03, ['name' => 'Kingdom 1000']);
        $items = $this->query->search(searchTerm: '1000', sort: 'relevance', limit: 100);

        self::assertSame([PuzzleFixture::PUZZLE_500_03], self::puzzleIds($items));
        self::assertSame(count($items), $this->query->count(searchTerm: '1000'));
    }

    public function testExactEanEndingInZeroIsTheMostRelevant(): void
    {
        // On a tie the newer listing comes first: PUZZLE_1000_01 is listed 4 and 15 days ago, PUZZLE_500_02 18 days ago
        $this->updatePuzzle(PuzzleFixture::PUZZLE_500_02, ['ean' => '4005556123450']);
        $this->updatePuzzle(PuzzleFixture::PUZZLE_1000_01, ['ean' => '4005556123450, 4005556000017']);

        foreach (['4005556123450', '23450'] as $search) {
            $items = $this->query->search(searchTerm: $search, sort: 'relevance', limit: 100);

            self::assertSame([PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_1000_01], self::puzzleIds($items), $search);
            self::assertSame(count($items), $this->query->count(searchTerm: $search));
        }

        self::assertSame([], $this->query->search(searchTerm: '3450'));
    }

    /**
     * @param array<MarketplaceListingItem> $items
     *
     * @return list<string>
     */
    private static function puzzleIds(array $items): array
    {
        return array_values(array_unique(array_map(static fn (MarketplaceListingItem $item): string => $item->puzzleId, $items)));
    }

    /**
     * @param array<string, string> $columns
     */
    private function updatePuzzle(string $puzzleId, array $columns): void
    {
        $assignments = implode(', ', array_map(static fn (string $column): string => "$column = :$column", array_keys($columns)));

        self::getContainer()->get(Connection::class)->executeStatement("UPDATE puzzle SET $assignments WHERE id = :id", [...$columns, 'id' => $puzzleId]);
    }

    /**
     * @return list<string>
     */
    private function sellerIds(): array
    {
        return array_values(array_map(
            static fn (MarketplaceListingItem $item): string => $item->sellerId,
            $this->query->search(limit: 1000),
        ));
    }

    private function block(string $blockerId, string $blockedId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
