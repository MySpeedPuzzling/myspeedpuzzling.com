<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use SpeedPuzzling\Web\Component\MarketplaceListing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class MarketplaceListingPiecesFilterTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testChipSetsTheBoundsAndTypedBoundsSelectTheChip(): void
    {
        $component = $this->createLiveComponent('MarketplaceListing', [], self::createClient());
        $component->setRouteLocale('en');

        $component->set('pieces', '2000-');
        $listing = $this->listingOf($component);
        self::assertSame(2000, $listing->piecesMin);
        self::assertNull($listing->piecesMax);

        $component->set('piecesMin', 1000);
        $component->set('piecesMax', 1000);
        self::assertSame('1000', $this->listingOf($component)->pieces);

        // Swapped bounds are put in order
        $component->set('piecesMin', 750);
        $component->set('piecesMax', 300);
        $listing = $this->listingOf($component);
        self::assertSame(300, $listing->piecesMin);
        self::assertSame(750, $listing->piecesMax);
        self::assertSame('300-750', $listing->pieces);

        $component->set('pieces', '');
        $listing = $this->listingOf($component);
        self::assertNull($listing->piecesMin);
        self::assertNull($listing->piecesMax);
    }

    public function testBoundsFromTheUrlSelectTheChip(): void
    {
        $component = $this->createLiveComponent('MarketplaceListing', ['piecesMin' => 500, 'piecesMax' => 500], self::createClient());
        $component->setRouteLocale('en');

        $crawler = $component->render()->crawler();
        self::assertNotNull($crawler->filter('#marketplace-pieces-500')->attr('checked'));
        self::assertNull($crawler->filter('#marketplace-pieces-any')->attr('checked'));
        self::assertSame('500', $crawler->filter('input[data-model="on(change)|piecesMin"]')->attr('value'));
    }

    private function listingOf(TestLiveComponent $component): MarketplaceListing
    {
        $listing = $component->component();
        self::assertInstanceOf(MarketplaceListing::class, $listing);

        return $listing;
    }
}
