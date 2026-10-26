<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use SpeedPuzzling\Web\Component\MarketplaceListing;
use SpeedPuzzling\Web\Results\MarketplaceListingItem;
use SpeedPuzzling\Web\Tests\BorrowSeeding;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * "On my wishlist" (docs/features/wishlist-availability.md): only listings of puzzles on the signed-in player's
 * wishlist, the count equal to the list; a guest's URL value is ignored; the label on cards of wishlist puzzles.
 */
final class MarketplaceListingWishlistFilterTest extends WebTestCase
{
    use BorrowSeeding;
    use InteractsWithLiveComponents;

    public function testOnlyListingsOfWishlistPuzzlesAndTheirLabel(): void
    {
        $client = self::createClient();

        $viewer = $this->seedPlayer('Wisher', userId: 'test|marketplace-wisher');
        $wished = $this->seedPuzzle(1000, 'Wished puzzle');
        $other = $this->seedPuzzle(1000, 'Other puzzle');
        $this->seedWished($viewer, $wished);
        $this->seedListing($this->seedPlayer('Seller one'), $wished);
        $this->seedListing($this->seedPlayer('Seller two'), $wished);
        $this->seedListing($this->seedPlayer('Seller three'), $other);

        TestingLogin::asPlayer($client, $viewer);
        $component = $this->createLiveComponent('MarketplaceListing', ['onMyWishlist' => true], $client);
        $component->setRouteLocale('en');

        $listing = $this->listingOf($component);
        $puzzleIds = array_values(array_unique(array_map(static fn (MarketplaceListingItem $item): string => $item->puzzleId, $listing->getItems())));

        self::assertSame([$wished], $puzzleIds);
        self::assertSame(2, $listing->getResultCount());
        self::assertTrue($listing->getItems()[0]->onViewerWishlist);
        self::assertStringContainsString('On your wishlist', $component->render()->toString());
    }

    public function testWithoutTheFilterWishlistPuzzlesCarryTheLabel(): void
    {
        $client = self::createClient();

        $viewer = $this->seedPlayer('Wisher', userId: 'test|marketplace-wisher-2');
        $wished = $this->seedPuzzle(1000, 'Wished puzzle');
        $other = $this->seedPuzzle(1000, 'Other puzzle');
        $this->seedWished($viewer, $wished);
        $this->seedListing($this->seedPlayer('Seller one'), $wished);
        $this->seedListing($this->seedPlayer('Seller two'), $other);

        TestingLogin::asPlayer($client, $viewer);
        $component = $this->createLiveComponent('MarketplaceListing', ['search' => 'puzzle'], $client);
        $component->setRouteLocale('en');
        $labels = [];

        foreach ($this->listingOf($component)->getItems() as $item) {
            if (in_array($item->puzzleId, [$wished, $other], true)) {
                $labels[$item->puzzleName] = $item->onViewerWishlist;
            }
        }

        ksort($labels);
        self::assertSame(['Other puzzle' => false, 'Wished puzzle' => true], $labels);
    }

    public function testAGuestsFilterIsIgnored(): void
    {
        $component = $this->createLiveComponent('MarketplaceListing', ['onMyWishlist' => true], self::createClient());
        $component->setRouteLocale('en');

        $withFilter = $this->listingOf($component)->getResultCount();

        $component->set('onMyWishlist', false);

        self::assertSame($this->listingOf($component)->getResultCount(), $withFilter);
    }

    private function listingOf(TestLiveComponent $component): MarketplaceListing
    {
        $listing = $component->component();
        self::assertInstanceOf(MarketplaceListing::class, $listing);

        return $listing;
    }
}
