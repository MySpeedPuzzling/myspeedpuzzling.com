<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use SpeedPuzzling\Web\Component\MarketplaceListing;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * "Pick up at an event" as a Live filter: kept in the URL like the other filters, the list starts over on a change.
 */
final class MarketplaceListingEventFilterTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const string FAIR = MarketplaceEventFixture::COMPETITION_SWAP_FAIR;

    public function testChoosingAnEventAndOnlyWhatTheyAreBringing(): void
    {
        $component = $this->listing();
        $all = $this->cards($component->render()->crawler())->count();

        $component->set('event', self::FAIR);
        $crawler = $component->render()->crawler();
        self::assertCount(1, $crawler->filter('[data-testid="marketplace-event-context"]'));
        self::assertCount(12, $this->cards($crawler));

        $component->set('onlyBringing', true);
        self::assertCount(2, $this->cards($component->render()->crawler()));

        $returnUrl = urldecode($this->listingOf($component)->getReturnUrl());
        self::assertStringContainsString('event=' . self::FAIR, $returnUrl);
        self::assertStringContainsString('onlyBringing=1', $returnUrl);

        $component->call('clearEvent');
        $crawler = $component->render()->crawler();
        self::assertCount(0, $crawler->filter('[data-testid="marketplace-event-context"]'));
        self::assertCount($all, $this->cards($crawler));
        self::assertFalse($this->listingOf($component)->onlyBringing);
        self::assertStringNotContainsString('event=', $this->listingOf($component)->getReturnUrl());
    }

    public function testTheListStartsOverWhenTheEventChanges(): void
    {
        $component = $this->listing();
        $component->call('loadMore');
        self::assertSame(2, $this->listingOf($component)->page);

        $component->set('event', self::FAIR);

        self::assertSame(1, $this->listingOf($component)->page);
    }

    public function testAnEventNoLongerAmongTheCachedOptionsIsStillFound(): void
    {
        // Nobody with a listing goes to WJPC 2024 - it is not an option, but it is a marketplace event
        $component = $this->listing();
        $component->set('event', CompetitionFixture::COMPETITION_WJPC_2024);

        $crawler = $component->render()->crawler();

        self::assertCount(1, $crawler->filter('[data-testid="marketplace-event-context"]'));
        self::assertCount(0, $this->cards($crawler));
        self::assertSame(CompetitionFixture::COMPETITION_WJPC_2024, $crawler->filter('#marketplaceEventFilter option[selected]')->attr('value'));
    }

    private function listing(): TestLiveComponent
    {
        $component = $this->createLiveComponent('MarketplaceListing', [], self::createClient());
        $component->setRouteLocale('en');

        return $component;
    }

    private function listingOf(TestLiveComponent $component): MarketplaceListing
    {
        $listing = $component->component();
        self::assertInstanceOf(MarketplaceListing::class, $listing);

        return $listing;
    }

    private function cards(Crawler $crawler): Crawler
    {
        return $crawler->filter('[id^="marketplace-listing-"]');
    }
}
