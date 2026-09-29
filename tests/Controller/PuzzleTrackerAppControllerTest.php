<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Results\CatalogueNumbers;
use SpeedPuzzling\Web\Services\CatalogueNumbersProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Cache\CacheInterface;

final class PuzzleTrackerAppControllerTest extends WebTestCase
{
    protected function tearDown(): void
    {
        // Leave no seeded snapshot behind for other tests
        self::getContainer()->get(CacheInterface::class)->delete(CatalogueNumbersProvider::CACHE_KEY);

        parent::tearDown();
    }

    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle-tracker-app');

        $this->assertResponseIsSuccessful();
    }

    public function testPageLeadsOnToTheDatabaseAndTheRestOfTheSite(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle-tracker-app');

        $this->assertResponseIsSuccessful();

        // In the page body, not only in the menu or the footer
        foreach (['/en/puzzle', '/en/ladder', '/en/guides', '/en/events', '/en/faq'] as $path) {
            self::assertCount(1, $crawler->filter(sprintf('#explore a[href="%s"]', $path)), $path);
        }

        self::assertCount(1, $crawler->filter('#puzzle-database a[href="/en/puzzle"]'));
    }

    public function testShowsTheCatalogueNumbersInTheLocaleOfThePage(): void
    {
        $browser = self::createClient();
        $this->seedCatalogueNumbers($browser);

        $crawler = $browser->request('GET', '/en/puzzle-tracker-app');
        $numbers = $crawler->filter('#puzzle-database .catalogue-numbers li');

        self::assertCount(4, $numbers);
        self::assertStringContainsString('40,622', $numbers->eq(0)->text());
        self::assertStringContainsString('puzzles', $numbers->eq(0)->text());
        self::assertStringContainsString('2,060', $numbers->eq(1)->text());
        self::assertStringContainsString('brands', $numbers->eq(1)->text());
        self::assertStringContainsString('23,554', $numbers->eq(2)->text());
        self::assertStringContainsString('EAN', $numbers->eq(2)->text());
        self::assertStringContainsString('510,602', $numbers->eq(3)->text());
        self::assertStringContainsString('solve times', $numbers->eq(3)->text());

        $crawler = $browser->request('GET', '/de/puzzle-tracker-app');
        self::assertStringContainsString('40.622', $crawler->filter('#puzzle-database .catalogue-numbers')->text());

        $crawler = $browser->request('GET', '/sledovani-puzzli-aplikace');
        $czech = $crawler->filter('#puzzle-database .catalogue-numbers li');
        self::assertStringContainsString("40\u{a0}622", $czech->eq(0)->text());
        // Czech plural for large numbers
        self::assertStringContainsString('značek', $czech->eq(1)->text());
    }

    private function seedCatalogueNumbers(KernelBrowser $browser): void
    {
        $cache = $browser->getContainer()->get(CacheInterface::class);
        $cache->delete(CatalogueNumbersProvider::CACHE_KEY);
        $cache->get(CatalogueNumbersProvider::CACHE_KEY, static fn (): CatalogueNumbers => new CatalogueNumbers(
            puzzles: 40622,
            brands: 2060,
            puzzlesWithEan: 23554,
            solveTimes: 510602,
        ));
    }
}
