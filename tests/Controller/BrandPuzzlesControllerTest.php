<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DOMElement;
use SpeedPuzzling\Web\Query\GetCataloguePuzzles;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use SpeedPuzzling\Web\Tests\CatalogueTestData;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\CataloguePagination;
use SpeedPuzzling\Web\Value\PiecesRange;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;

final class BrandPuzzlesControllerTest extends WebTestCase
{
    use CatalogueTestData;
    use QueryCountAssertions;

    protected function tearDown(): void
    {
        // Tests below add puzzles (rolled back by DAMA) - do not leave their stats cached
        if (self::$booted) {
            self::clearCatalogueStatsCache(self::getContainer());
        }

        parent::tearDown();
    }

    public function testAnonymousUserCanAccessBrandHub(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle/brand/ravensburger');

        $this->assertResponseIsSuccessful();

        self::assertStringContainsString('Ravensburger Puzzles', (string) $crawler->filter('title')->text());
        self::assertStringContainsString('Ravensburger Puzzles', (string) $crawler->filter('h1')->text());
    }

    public function testLoggedInUserCanAccessBrandHub(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/puzzle/brand/ravensburger');

        $this->assertResponseIsSuccessful();
    }

    public function testSignedInPlayerSeesTheirBestTimeOnTheListedPuzzlesWithoutBeingRanked(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $this->startCountingQueries($browser);

        $crawler = $browser->request('GET', '/en/puzzle/brand/ravensburger');

        $this->assertResponseIsSuccessful();

        // The same times the cards used to take from the player's ranking
        $ranking = self::getContainer()->get(GetRanking::class)->allForPlayer(PlayerFixture::PLAYER_REGULAR);
        $formatter = new PuzzlingTimeFormatter();
        $shown = 0;

        foreach ($crawler->filter('[id^="puzzle-list-item-"]') as $card) {
            self::assertInstanceOf(DOMElement::class, $card);
            $puzzleId = substr($card->getAttribute('id'), strlen('puzzle-list-item-'));
            $myTime = (new Crawler($card))->filter('.puzzle-times-info .ci-user');

            if (isset($ranking[$puzzleId]) === false) {
                self::assertCount(0, $myTime, $puzzleId);

                continue;
            }

            self::assertCount(1, $myTime, $puzzleId);
            self::assertStringContainsString($formatter->formatTime($ranking[$puzzleId]->time), $myTime->ancestors()->first()->text());
            $shown++;
        }

        self::assertGreaterThan(0, $shown, 'Premise: John solved some of the listed puzzles');

        // A best time needs no rank against everybody on every puzzle John ever solved
        self::assertSame([], array_values(array_filter(
            $this->executedSql($browser),
            static fn (string $sql): bool => str_contains($sql, 'PlayerPuzzles'),
        )));
    }

    public function testBrandWithEnoughDataIsIndexable(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle/brand/ravensburger');

        $this->assertResponseIsSuccessful();
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testThinBrandRendersWithNoindex(): void
    {
        $browser = self::createClient();

        // "Unknown Brand" has a single (unapproved) puzzle and no recorded solves
        $crawler = $browser->request('GET', '/en/puzzle/brand/unknown-brand');

        $this->assertResponseIsSuccessful();
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testBrandHubShowsPuzzleGrid(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle/brand/ravensburger');

        $this->assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.puzzle-list-item')->count());
    }

    public function testUnknownSlugReturns404(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle/brand/does-not-exist');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testLocalizedRouteWorks(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/puzzle/znacka/ravensburger');

        $this->assertResponseIsSuccessful();
    }

    public function testFirstPageListsTheWholeCatalogueWithoutFilterLinks(): void
    {
        $browser = self::createClient();
        self::clearCatalogueStatsCache($browser->getContainer());

        $count = self::ravensburgerPuzzlesCount($browser->getContainer());
        self::assertLessThanOrEqual(CataloguePagination::PER_PAGE, $count, 'The fixtures fit on one page');

        $crawler = $browser->request('GET', '/en/puzzle/brand/ravensburger');

        $this->assertResponseIsSuccessful();

        self::assertJsonLdIsValid($crawler);

        // Every visible puzzle of the brand, not just the most solved ones - and no pagination for one page
        self::assertCount($count, $crawler->filter('.puzzle-list-item'));
        self::assertStringContainsString(sprintf('Puzzles 1–%d of %d', $count, $count), $crawler->text());
        self::assertCount(0, $crawler->filter('nav.catalogue-pagination'));

        // Stats on page 1, no "View all" button to a ?brand= filter URL
        self::assertStringContainsString('Median solo time', $crawler->text());
        self::assertCount(0, $crawler->filter('main a[href*="?brand="]'), 'No "View all" filter link any more');

        // Links: brand directory, and the indexable brand × pieces pages
        self::assertGreaterThan(0, $crawler->filter('a[href="/en/puzzle/brands"]')->count());
        self::assertGreaterThan(0, $crawler->filter('a[href="/en/puzzle/brand/ravensburger/500-pieces"]')->count());
        self::assertGreaterThan(0, $crawler->filter('a[href="/en/puzzle/brand/ravensburger/1000-pieces"]')->count());
        // 300 pieces: a single puzzle - its page exists but is noindex, so the hub does not link it
        self::assertCount(0, $crawler->filter('a[href="/en/puzzle/brand/ravensburger/300-pieces"]'));
    }

    public function testMedianByPiecesLinksToTheBrandPiecesPage(): void
    {
        $browser = self::createClient();
        self::clearCatalogueStatsCache($browser->getContainer());

        $crawler = $browser->request('GET', '/en/puzzle/brand/ravensburger');

        $this->assertResponseIsSuccessful();

        $medianList = $crawler->filter('h2:contains("Median solo time by piece count") + ul');
        self::assertCount(1, $medianList);
        self::assertSame('/en/puzzle/brand/ravensburger/500-pieces', $medianList->filter('a')->attr('href'));
        self::assertCount(0, $medianList->filter('a[href="/en/puzzle/500-pieces"]'));
    }

    public function testSecondPage(): void
    {
        $browser = self::createClient();
        self::clearCatalogueStatsCache($browser->getContainer());
        // 50 puzzles = 2 pages, 2 puzzles on the second
        self::addFillerPuzzles($browser->getContainer(), ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 1000, 50 - self::ravensburgerPuzzlesCount($browser->getContainer()));

        $crawler = $browser->request('GET', '/en/puzzle/brand/ravensburger');

        $this->assertResponseIsSuccessful();
        self::assertCount(48, $crawler->filter('.puzzle-list-item'));
        self::assertSame('/en/puzzle/brand/ravensburger/page/2', $crawler->filter('nav.catalogue-pagination a[rel="next"]')->attr('href'));

        $crawler = $browser->request('GET', '/en/puzzle/brand/ravensburger/page/2');

        $this->assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.puzzle-list-item'));
        self::assertSame('Ravensburger Puzzles – Page 2 – MySpeedPuzzling', $crawler->filter('title')->text());
        self::assertSame('Ravensburger Puzzles – Page 2', $crawler->filter('h1')->text());
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'), 'Same indexability as page 1');
        self::assertStringContainsString('Puzzles 49–50 of 50', $crawler->text());
        self::assertJsonLdIsValid($crawler);

        // No stats blocks on pages 2+
        self::assertStringNotContainsString('Median solo time', $crawler->text());

        // Self-canonical, hreflang alternates point to page 2 in every locale
        self::assertSame('http://localhost/en/puzzle/brand/ravensburger/page/2', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame('http://localhost/puzzle/znacka/ravensburger/strana/2', $crawler->filter('link[rel="alternate"][hreflang="cs"]')->attr('href'));
        self::assertSame('http://localhost/de/puzzle/marke/ravensburger/seite/2', $crawler->filter('link[rel="alternate"][hreflang="de"]')->attr('href'));
        self::assertSame('http://localhost/es/puzzles/marca/ravensburger/pagina/2', $crawler->filter('link[rel="alternate"][hreflang="es"]')->attr('href'));
        self::assertSame('http://localhost/fr/puzzle/marque/ravensburger/page/2', $crawler->filter('link[rel="alternate"][hreflang="fr"]')->attr('href'));
        self::assertSame(
            'http://localhost/ja/' . rawurlencode('パズル') . '/' . rawurlencode('ブランド') . '/ravensburger/' . rawurlencode('ページ') . '/2',
            $crawler->filter('link[rel="alternate"][hreflang="ja"]')->attr('href'),
        );
        self::assertSame('http://localhost/en/puzzle/brand/ravensburger/page/2', $crawler->filter('link[rel="alternate"][hreflang="x-default"]')->attr('href'));

        // Crawlable links back: page 1 is the brand's URL, never /page/1
        self::assertSame('/en/puzzle/brand/ravensburger', $crawler->filter('nav.catalogue-pagination a[rel="prev"]')->attr('href'));
        self::assertCount(0, $crawler->filter('a[href$="/page/1"]'));
        self::assertSame('2', $crawler->filter('nav.catalogue-pagination [aria-current="page"]')->text());
    }

    public function testLocalizedSecondPage(): void
    {
        $browser = self::createClient();
        self::addFillerPuzzles($browser->getContainer(), ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 1000, 50 - self::ravensburgerPuzzlesCount($browser->getContainer()));

        $crawler = $browser->request('GET', '/puzzle/znacka/ravensburger/strana/2');

        $this->assertResponseIsSuccessful();
        self::assertSame('Puzzle Ravensburger – strana 2', $crawler->filter('h1')->text());
        self::assertSame('http://localhost/puzzle/znacka/ravensburger/strana/2', $crawler->filter('link[rel="canonical"]')->attr('href'));
    }

    public function testPageOneRedirectsToTheBrandUrl(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle/brand/ravensburger/page/1');
        $this->assertResponseRedirects('/en/puzzle/brand/ravensburger', 301);

        $browser->request('GET', '/de/puzzle/marke/ravensburger/seite/1');
        $this->assertResponseRedirects('/de/puzzle/marke/ravensburger', 301);
    }

    public function testPageBeyondTheLastReturns404(): void
    {
        $browser = self::createClient();

        // 20 puzzles = a single page
        $browser->request('GET', '/en/puzzle/brand/ravensburger/page/2');
        $this->assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/puzzle/brand/does-not-exist/page/2');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidPageReturns404(): void
    {
        $browser = self::createClient();

        foreach (['0', '02', 'abc', '-1', '123456'] as $page) {
            $browser->request('GET', '/en/puzzle/brand/ravensburger/page/' . $page);
            $this->assertResponseStatusCodeSame(404, sprintf('Page "%s"', $page));
        }
    }

    private static function ravensburgerPuzzlesCount(ContainerInterface $container): int
    {
        return $container->get(GetCataloguePuzzles::class)->count(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, PiecesRange::any());
    }
}
