<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Query\GetCataloguePuzzles;
use SpeedPuzzling\Web\Tests\CatalogueTestData;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\PiecesRange;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PiecesPuzzlesControllerTest extends WebTestCase
{
    use CatalogueTestData;

    protected function tearDown(): void
    {
        // Tests below add puzzles (rolled back by DAMA) - do not leave their stats cached
        if (self::$booted) {
            self::clearCatalogueStatsCache(self::getContainer());
        }

        parent::tearDown();
    }

    public function testAnonymousUserCanAccessPiecesHub(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle/1000-pieces');

        $this->assertResponseIsSuccessful();

        self::assertStringContainsString('1000 Piece Puzzles', (string) $crawler->filter('title')->text());
        self::assertStringContainsString('1000 Piece Puzzles', (string) $crawler->filter('h1')->text());
    }

    public function testLoggedInUserCanAccessPiecesHub(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/puzzle/500-pieces');

        $this->assertResponseIsSuccessful();
    }

    public function testPiecesHubShowsPuzzleGrid(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle/1000-pieces');

        $this->assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.puzzle-list-item')->count());
        self::assertCount(0, $crawler->filter('main a[href*="?pieces="]'), 'No "View all" filter link any more');
    }

    public function testDisallowedPiecesValueReturns404(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle/123-pieces');
        $this->assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/puzzle/123-pieces/page/2');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testLocalizedRouteWorks(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/puzzle/1000-dilku');

        $this->assertResponseIsSuccessful();
    }

    public function testPuzzleDetailRouteIsNotShadowed(): void
    {
        $browser = self::createClient();

        // Regression guard: the higher-priority pieces route must not swallow
        // /en/puzzle/{puzzleId} URLs.
        $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
    }

    public function testPopularBrandsLinkToTheBrandPiecesPageWhenIndexable(): void
    {
        $browser = self::createClient();
        self::clearCatalogueStatsCache($browser->getContainer());

        $crawler = $browser->request('GET', '/en/puzzle/500-pieces');

        $this->assertResponseIsSuccessful();
        self::assertJsonLdIsValid($crawler);

        $badges = $crawler->filter('h2:contains("Popular 500 piece puzzle brands") + p a');
        $targets = [];
        foreach ($badges as $badge) {
            assert($badge instanceof \DOMElement);
            $targets[trim($badge->textContent)] = $badge->getAttribute('href');
        }

        // Ravensburger 500: 8 puzzles with solves - its own page; Trefl 500: 2 puzzles - the brand hub
        self::assertSame('/en/puzzle/brand/ravensburger/500-pieces', $targets['Ravensburger'] ?? null);
        self::assertSame('/en/puzzle/brand/trefl', $targets['Trefl'] ?? null);
    }

    public function testSecondPage(): void
    {
        $browser = self::createClient();
        // 49 puzzles = 2 pages, the second with a single puzzle
        $count = $browser->getContainer()->get(GetCataloguePuzzles::class)->count(null, PiecesRange::between(1000, 1000));
        self::addFillerPuzzles($browser->getContainer(), ManufacturerFixture::MANUFACTURER_TREFL, 1000, 49 - $count);

        $crawler = $browser->request('GET', '/en/puzzle/1000-pieces/page/2');

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.puzzle-list-item'));
        self::assertSame('1000 Piece Puzzles – Page 2', $crawler->filter('h1')->text());
        self::assertJsonLdIsValid($crawler);
        self::assertStringNotContainsString('Median solo time', $crawler->text());
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame('http://localhost/en/puzzle/1000-pieces/page/2', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame('http://localhost/puzzle/1000-dilku/strana/2', $crawler->filter('link[rel="alternate"][hreflang="cs"]')->attr('href'));
        self::assertSame('http://localhost/es/puzzles/1000-piezas/pagina/2', $crawler->filter('link[rel="alternate"][hreflang="es"]')->attr('href'));
        self::assertSame('/en/puzzle/1000-pieces', $crawler->filter('nav.catalogue-pagination a[rel="prev"]')->attr('href'));

        $browser->request('GET', '/en/puzzle/1000-pieces/page/3');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testPageOneRedirectsToTheHubUrl(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle/1000-pieces/page/1');
        $this->assertResponseRedirects('/en/puzzle/1000-pieces', 301);

        $browser->request('GET', '/puzzle/1000-dilku/strana/1');
        $this->assertResponseRedirects('/puzzle/1000-dilku', 301);
    }

    public function testPageBeyondTheLastReturns404(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle/1000-pieces/page/2');

        $this->assertResponseStatusCodeSame(404);
    }
}
