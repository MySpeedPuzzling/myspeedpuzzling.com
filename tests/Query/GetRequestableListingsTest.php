<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetRequestableListings;
use SpeedPuzzling\Web\Tests\ListingRequestSeeding;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\ListingAskSkipReason;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The one rule of what a buyer may ask a seller for (docs/features/marketplace/12-listing-requests.md).
 */
final class GetRequestableListingsTest extends KernelTestCase
{
    use ListingRequestSeeding;

    private GetRequestableListings $query;
    private string $buyer;
    private string $seller;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetRequestableListings::class);
        $this->buyer = $this->seedPlayer('Jana');
        $this->seller = $this->seedPlayer('Anna', country: 'cz');
    }

    public function testAListingCarriesTheSellersTermsAndCurrency(): void
    {
        $listing = $this->seedListing($this->seller, $this->seedPuzzle(1000, 'Lighthouse'), shipsTo: ['cz', 'sk']);

        $found = $this->query->forListings($this->buyer, [$listing])[$listing] ?? null;

        self::assertNotNull($found);
        self::assertSame('Anna', $found->sellerName);
        self::assertSame('Lighthouse', $found->puzzleName);
        self::assertSame(10.0, $found->price);
        self::assertSame('EUR', $found->currency);
        self::assertSame(['cz', 'sk'], $found->shippingCountries);
        self::assertNull($found->skipReason());
        self::assertTrue($found->hasPrice());
    }

    public function testABlockEitherWayOrASecretPuzzleLeavesTheListingOut(): void
    {
        $blockedBySeller = $this->seedPlayer('Blocks me');
        $blockedByBuyer = $this->seedPlayer('I block');
        $this->seedBlock($blockedBySeller, $this->buyer);
        $this->seedBlock($this->buyer, $blockedByBuyer);

        $listings = [
            $this->seedListing($blockedBySeller, $this->seedPuzzle(500, 'A')),
            $this->seedListing($blockedByBuyer, $this->seedPuzzle(500, 'B')),
            $this->seedListing($this->seller, $this->seedPuzzle(500, 'Secret', hideUntil: TestClock::now()->modify('+1 year'))),
            Uuid::uuid7()->toString(),
        ];

        self::assertSame([], $this->query->forListings($this->buyer, $listings));
    }

    public function testOwnReservedAndAlreadyAskedListingsAreFlagged(): void
    {
        $own = $this->seedListing($this->buyer, $this->seedPuzzle(500, 'Own'));
        $reserved = $this->seedListing($this->seller, $this->seedPuzzle(500, 'Reserved'));
        $this->seedReservedFor($reserved, null);
        $reservedForBuyer = $this->seedListing($this->seller, $this->seedPuzzle(500, 'For me'));
        $this->seedReservedFor($reservedForBuyer, $this->buyer);
        $asked = $this->seedListing($this->seller, $this->seedPuzzle(500, 'Asked'));
        $this->askFor($this->buyer, [$this->seller => [$asked]]);

        $found = $this->query->forListings($this->buyer, [$own, $reserved, $reservedForBuyer, $asked]);

        self::assertSame(ListingAskSkipReason::Own, $found[$own]->skipReason());
        self::assertSame(ListingAskSkipReason::Reserved, $found[$reserved]->skipReason());
        self::assertNull($found[$reservedForBuyer]->skipReason());
        self::assertSame(ListingAskSkipReason::AlreadyAsked, $found[$asked]->skipReason());
    }

    public function testAPrivateSellerIsNamedOnlyToTheirAllowList(): void
    {
        $private = $this->seedPlayer('Private Paula', private: true);
        $listing = $this->seedListing($private, $this->seedPuzzle(500, 'Quiet'));

        self::assertNull($this->query->forListings($this->buyer, [$listing])[$listing]->sellerName);

        $this->seedAllowList($private, $this->buyer);

        self::assertSame('Private Paula', $this->query->forListings($this->buyer, [$listing])[$listing]->sellerName);
    }
}
