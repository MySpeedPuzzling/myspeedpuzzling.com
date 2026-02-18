<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetMarketplaceListings;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
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
        // Search for "Puzzle 1" which is PUZZLE_500_01 with 2 offers from different sellers
        $items = $this->query->search(searchTerm: 'Puzzle 1');

        $puzzle500_01Items = array_filter(
            $items,
            static fn ($item) => $item->puzzleId === PuzzleFixture::PUZZLE_500_01,
        );

        self::assertCount(2, $puzzle500_01Items);

        $sellerIds = array_map(static fn ($item) => $item->sellerId, $puzzle500_01Items);
        self::assertCount(2, array_unique($sellerIds));
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
}
