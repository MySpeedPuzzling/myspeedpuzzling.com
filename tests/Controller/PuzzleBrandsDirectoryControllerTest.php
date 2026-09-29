<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\CatalogueTestData;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PuzzleBrandsDirectoryControllerTest extends WebTestCase
{
    use CatalogueTestData;

    public function testListsAndLinksTheIndexableBrandHubs(): void
    {
        $browser = self::createClient();
        self::clearCatalogueStatsCache($browser->getContainer());

        $crawler = $browser->request('GET', '/en/puzzle/brands');

        $this->assertResponseIsSuccessful();
        self::assertJsonLdIsValid($crawler);
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame('Jigsaw Puzzle Brands A–Z', $crawler->filter('h1')->text());
        self::assertSame('http://localhost/en/puzzle/brands', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame('http://localhost/puzzle/znacky', $crawler->filter('link[rel="alternate"][hreflang="cs"]')->attr('href'));

        // A–Z sections link every indexable brand hub
        self::assertCount(1, $crawler->filter('#brands-r a[href="/en/puzzle/brand/ravensburger"]'));
        self::assertCount(1, $crawler->filter('#brands-t a[href="/en/puzzle/brand/trefl"]'));
        self::assertStringContainsString('Ravensburger', $crawler->filter('#brands-r')->text());
        self::assertCount(1, $crawler->filter('a[href="#brands-r"]'), 'Letter navigation');

        // "Most popular brands" block at the top
        self::assertGreaterThan(0, $crawler->filter('h2:contains("Most popular brands") + p a[href="/en/puzzle/brand/ravensburger"]')->count());

        // Unknown Brand is unapproved with a single unsolved puzzle - its hub is noindex
        self::assertStringNotContainsString('unknown-brand', (string) $browser->getResponse()->getContent());
    }

    public function testIsNotMistakenForAPuzzleId(): void
    {
        $browser = self::createClient();

        foreach (['/en/puzzle/brands', '/puzzle/znacky', '/de/puzzle/marken', '/es/puzzles/marcas', '/fr/puzzle/marques', '/ja/パズル/ブランド'] as $path) {
            $crawler = $browser->request('GET', $path);

            $this->assertResponseIsSuccessful($path);
            self::assertGreaterThan(0, $crawler->filter('section[id^="brands-"]')->count(), $path);
        }
    }

    public function testLinkedFromThePuzzleDatabaseAndTheBrandHub(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle');
        $this->assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('a[href="/en/puzzle/brands"]')->count());

        $crawler = $browser->request('GET', '/en/puzzle/brand/trefl');
        $this->assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('a[href="/en/puzzle/brands"]')->count());
    }
}
