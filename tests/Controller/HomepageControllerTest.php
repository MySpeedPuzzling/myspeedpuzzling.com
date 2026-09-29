<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Results\CatalogueNumbers;
use SpeedPuzzling\Web\Services\CatalogueNumbersProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Cache\CacheInterface;

final class HomepageControllerTest extends WebTestCase
{
    public function testAnonymousUserGetsMarketingHomepageAtDomainRoot(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/');

        // The domain root is a real 200 marketing page (not a redirect):
        // required for Google's site-name system and the hreflang x-default anchor.
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Speed Puzzling');
        // Live counters must be server-rendered for SEO / no-JS visitors.
        $this->assertSelectorExists('[data-controller="count-up"]');
        $this->assertSelectorExists('[data-count-up-key="pieces"]');
    }

    public function testLoggedInUserIsRedirectedFromDomainRootToHub(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/');

        $this->assertResponseRedirects('/en/hub');

        $browser->followRedirect();

        $this->assertResponseIsSuccessful();
    }

    public function testAnonymousUserCanAccessCzechHomepage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/cs');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'speed puzzling');
    }

    public function testAnonymousUserCanAccessGermanHomepage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/de');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Speed Puzzling');
    }

    public function testLoggedInUserCanAccessLocalizedHomepage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/cs');

        $this->assertResponseIsSuccessful();
    }

    public function testDatabaseSectionQuotesTheCatalogueNumbers(): void
    {
        $browser = self::createClient();

        $cache = $browser->getContainer()->get(CacheInterface::class);
        $cache->delete(CatalogueNumbersProvider::CACHE_KEY);
        $cache->get(CatalogueNumbersProvider::CACHE_KEY, static fn (): CatalogueNumbers => new CatalogueNumbers(
            puzzles: 40622,
            brands: 2060,
            puzzlesWithEan: 23554,
            solveTimes: 510602,
        ));

        try {
            $crawler = $browser->request('GET', '/');

            $this->assertResponseIsSuccessful();

            // Server-rendered in the "Browse the Jigsaw Puzzle Database" section, next to its link to the database
            $section = $crawler->filter('.homepage-feature')->reduce(
                static fn (Crawler $feature): bool => $feature->filter('.catalogue-numbers')->count() > 0,
            );
            self::assertCount(1, $section);
            self::assertStringContainsString('Browse the Jigsaw Puzzle Database', $section->filter('h2')->text());
            self::assertCount(1, $section->filter('a[href="/en/puzzle"]'));

            $numbers = $section->filter('.catalogue-numbers li');
            self::assertCount(4, $numbers);
            self::assertSame('40,622 puzzles', $numbers->eq(0)->text());
            self::assertSame('2,060 brands', $numbers->eq(1)->text());
            self::assertSame('23,554 puzzles with an EAN barcode', $numbers->eq(2)->text());
            self::assertSame('510,602 recorded solve times', $numbers->eq(3)->text());
        } finally {
            $cache->delete(CatalogueNumbersProvider::CACHE_KEY);
        }
    }
}
