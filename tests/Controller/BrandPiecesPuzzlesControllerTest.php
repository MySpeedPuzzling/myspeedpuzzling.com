<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetCataloguePuzzles;
use SpeedPuzzling\Web\Tests\CatalogueTestData;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\PiecesRange;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Fixtures: Ravensburger has 8 visible 500-piece puzzles and 6 1000-piece ones,
 * all solved (indexable); a single 300-piece puzzle (page exists, noindex).
 * Trefl has at most 2 puzzles of a size, Unknown Brand is unapproved.
 */
final class BrandPiecesPuzzlesControllerTest extends WebTestCase
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

    public function testIndexableCombination(): void
    {
        $browser = self::createClient();
        self::clearCatalogueStatsCache($browser->getContainer());

        $crawler = $browser->request('GET', '/en/puzzle/brand/ravensburger/500-pieces');

        $this->assertResponseIsSuccessful();
        self::assertJsonLdIsValid($crawler);
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame('Ravensburger 500 Piece Puzzles – Solve Times – MySpeedPuzzling', $crawler->filter('title')->text());
        self::assertSame('Ravensburger 500-Piece Puzzles', $crawler->filter('h1')->text());
        self::assertStringContainsString('Ravensburger 500 piece jigsaw puzzles: 8 puzzles', (string) $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertSame('http://localhost/en/puzzle/brand/ravensburger/500-pieces', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame('http://localhost/puzzle/znacka/ravensburger/500-dilku', $crawler->filter('link[rel="alternate"][hreflang="cs"]')->attr('href'));
        self::assertSame('http://localhost/de/puzzle/marke/ravensburger/500-teile', $crawler->filter('link[rel="alternate"][hreflang="de"]')->attr('href'));

        // Stats: puzzles, solves, median solo
        self::assertStringContainsString('Median solo time', $crawler->text());
        self::assertCount(8, $crawler->filter('.puzzle-list-item'));
        foreach ($crawler->filter('.puzzle-list-item .puzzle-name') as $name) {
            self::assertStringContainsString('500', (string) $name->textContent);
        }

        // Links: brand hub, the global pieces hub, the brand's other piece counts
        self::assertGreaterThan(0, $crawler->filter('a[href="/en/puzzle/brand/ravensburger"]')->count());
        self::assertGreaterThan(0, $crawler->filter('a[href="/en/puzzle/500-pieces"]')->count());
        self::assertGreaterThan(0, $crawler->filter('a[href="/en/puzzle/brand/ravensburger/1000-pieces"]')->count());
        self::assertGreaterThan(0, $crawler->filter('a[href="/en/puzzle/brand/ravensburger/300-pieces"]')->count());
        self::assertCount(0, $crawler->filter('a[href="/en/puzzle/brand/ravensburger/500-pieces"]'), 'No link to itself');

        // Breadcrumb JSON-LD: Database › Brand › Brand N pieces
        $breadcrumbItems = null;
        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            $data = json_decode((string) $script->textContent, true);
            if (is_array($data) && ($data['@type'] ?? null) === 'BreadcrumbList' && is_array($data['itemListElement'] ?? null)) {
                /** @var list<array{name: string, item?: string}> $breadcrumbItems */
                $breadcrumbItems = $data['itemListElement'];
            }
        }
        self::assertIsArray($breadcrumbItems);
        self::assertSame(
            ['Jigsaw Puzzle Database', 'Ravensburger', 'Ravensburger 500-Piece Puzzles'],
            array_column($breadcrumbItems, 'name'),
        );
        self::assertSame(
            ['http://localhost/en/puzzle', 'http://localhost/en/puzzle/brand/ravensburger'],
            array_column($breadcrumbItems, 'item'),
        );
    }

    public function testThinCombinationIsNoindex(): void
    {
        $browser = self::createClient();
        self::clearCatalogueStatsCache($browser->getContainer());

        // A single Ravensburger 300-piece puzzle
        $crawler = $browser->request('GET', '/en/puzzle/brand/ravensburger/300-pieces');
        $this->assertResponseIsSuccessful();
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));

        // Two Trefl 500-piece puzzles
        $crawler = $browser->request('GET', '/en/puzzle/brand/trefl/500-pieces');
        $this->assertResponseIsSuccessful();
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testCombinationOfANoindexBrandIsNoindex(): void
    {
        $browser = self::createClient();
        self::clearCatalogueStatsCache($browser->getContainer());
        // Unknown Brand (unapproved) gets plenty of 1000-piece puzzles - still noindex
        self::addFillerPuzzles($browser->getContainer(), ManufacturerFixture::MANUFACTURER_UNAPPROVED, 1000, 10);

        $crawler = $browser->request('GET', '/en/puzzle/brand/unknown-brand/1000-pieces');

        $this->assertResponseIsSuccessful();
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testCombinationBecomesIndexableWithEnoughPuzzles(): void
    {
        $browser = self::createClient();
        self::clearCatalogueStatsCache($browser->getContainer());
        // Trefl 500: 2 puzzles (one solved) + 4 = 6
        self::addFillerPuzzles($browser->getContainer(), ManufacturerFixture::MANUFACTURER_TREFL, 500, 4);

        $crawler = $browser->request('GET', '/en/puzzle/brand/trefl/500-pieces');

        $this->assertResponseIsSuccessful();
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    public function testNotFound(): void
    {
        $browser = self::createClient();

        // Not a piece count with a hub (Ravensburger does have a 4000-piece puzzle)
        $browser->request('GET', '/en/puzzle/brand/ravensburger/4000-pieces');
        $this->assertResponseStatusCodeSame(404);

        // An allowed piece count without any puzzle of the brand
        $browser->request('GET', '/en/puzzle/brand/ravensburger/750-pieces');
        $this->assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/puzzle/brand/does-not-exist/500-pieces');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testCombinationWithOnlyHiddenPuzzlesIsNotFound(): void
    {
        $browser = self::createClient();

        $browser->getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO puzzle (id, pieces_count, name, approved, manufacturer_id, is_available, hide_until)
             VALUES (gen_random_uuid(), 750, 'Secret competition puzzle', true, :brandId, true, now() + interval '30 days')",
            ['brandId' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER],
        );

        $browser->request('GET', '/en/puzzle/brand/ravensburger/750-pieces');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testPagination(): void
    {
        $browser = self::createClient();
        self::clearCatalogueStatsCache($browser->getContainer());

        $browser->request('GET', '/en/puzzle/brand/ravensburger/500-pieces/page/2');
        $this->assertResponseStatusCodeSame(404, '8 puzzles = one page');

        $browser->request('GET', '/en/puzzle/brand/ravensburger/500-pieces/page/1');
        $this->assertResponseRedirects('/en/puzzle/brand/ravensburger/500-pieces', 301);

        $browser->request('GET', '/puzzle/znacka/ravensburger/500-dilku/strana/1');
        $this->assertResponseRedirects('/puzzle/znacka/ravensburger/500-dilku', 301);

        $count = $browser->getContainer()->get(GetCataloguePuzzles::class)->count(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, PiecesRange::between(500, 500));
        self::addFillerPuzzles($browser->getContainer(), ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 500, 49 - $count);

        $crawler = $browser->request('GET', '/en/puzzle/brand/ravensburger/500-pieces/page/2');

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.puzzle-list-item'));
        self::assertSame('Ravensburger 500-Piece Puzzles – Page 2', $crawler->filter('h1')->text());
        self::assertJsonLdIsValid($crawler);
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertStringNotContainsString('Median solo time', $crawler->text());
        self::assertSame('http://localhost/en/puzzle/brand/ravensburger/500-pieces/page/2', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame('http://localhost/fr/puzzle/marque/ravensburger/500-pieces/page/2', $crawler->filter('link[rel="alternate"][hreflang="fr"]')->attr('href'));
        self::assertSame('http://localhost/puzzle/znacka/ravensburger/500-dilku/strana/2', $crawler->filter('link[rel="alternate"][hreflang="cs"]')->attr('href'));
        self::assertSame('/en/puzzle/brand/ravensburger/500-pieces', $crawler->filter('nav.catalogue-pagination a[rel="prev"]')->attr('href'));
    }

    public function testLocalizedRoutes(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/puzzle/znacka/ravensburger/500-dilku');
        $this->assertResponseIsSuccessful();
        self::assertSame('Puzzle Ravensburger 500 dílků', $crawler->filter('h1')->text());

        foreach (['/es/puzzles/marca/ravensburger/500-piezas', '/fr/puzzle/marque/ravensburger/500-pieces', '/de/puzzle/marke/ravensburger/500-teile', '/ja/パズル/ブランド/ravensburger/500ピース'] as $path) {
            $browser->request('GET', $path);
            $this->assertResponseIsSuccessful($path);
        }
    }

    public function testLoggedInUserCanAccess(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/puzzle/brand/ravensburger/1000-pieces');

        $this->assertResponseIsSuccessful();
    }
}
