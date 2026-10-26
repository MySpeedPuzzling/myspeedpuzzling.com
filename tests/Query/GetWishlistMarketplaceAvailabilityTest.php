<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetWishlistMarketplaceAvailability;
use SpeedPuzzling\Web\Tests\BorrowSeeding;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The offers counted on a wishlist card (docs/features/wishlist-availability.md): published, not reserved, not the
 * viewer's own, never a secret puzzle; "ships to you" by the seller's shipping countries.
 */
final class GetWishlistMarketplaceAvailabilityTest extends KernelTestCase
{
    use BorrowSeeding;

    public function testCountsAvailableOffersAndThoseShippedToTheViewer(): void
    {
        self::bootKernel();

        $viewer = $this->seedPlayer('Viewer', country: 'cz');
        $puzzle = $this->seedPuzzle(1000, 'Night Garden');
        $nothing = $this->seedPuzzle(500, 'Nobody sells it');

        $this->seedListing($this->seedPlayer('Ships to cz'), $puzzle, shipsTo: ['cz', 'sk']);
        $this->seedListing($this->seedPlayer('Ships to de'), $puzzle, shipsTo: ['de']);
        $this->seedListing($this->seedPlayer('Reserved'), $puzzle, reserved: true, shipsTo: ['cz']);
        $this->seedListing($this->seedPlayer('Unpublished'), $puzzle, published: false, shipsTo: ['cz']);
        $this->seedListing($viewer, $puzzle, shipsTo: ['cz']);

        $offers = self::getContainer()->get(GetWishlistMarketplaceAvailability::class)->forPuzzles([$puzzle, $nothing], $viewer, 'cz');

        self::assertSame([$puzzle], array_keys($offers));
        self::assertSame(2, $offers[$puzzle]->offers);
        self::assertSame(1, $offers[$puzzle]->shipsToViewer);
    }

    public function testWithoutACountryNothingIsSaidAboutShipping(): void
    {
        self::bootKernel();

        $puzzle = $this->seedPuzzle(1000);
        $this->seedListing($this->seedPlayer('Seller'), $puzzle, shipsTo: ['cz']);

        $offers = self::getContainer()->get(GetWishlistMarketplaceAvailability::class)->forPuzzles([$puzzle], null, null);

        self::assertSame(1, $offers[$puzzle]->offers);
        self::assertNull($offers[$puzzle]->shipsToViewer);
    }

    public function testASecretPuzzleHasNoOffers(): void
    {
        self::bootKernel();

        $secret = $this->seedPuzzle(500, hideUntil: TestClock::now()->modify('+5 days'));
        $this->seedListing($this->seedPlayer('Seller'), $secret);

        self::assertSame([], self::getContainer()->get(GetWishlistMarketplaceAvailability::class)->forPuzzles([$secret], null, null));
    }
}
