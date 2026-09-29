<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SitemapBrandsControllerTest extends WebTestCase
{
    public function testSitemapContainsIndexableCataloguePages(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/sitemap-brands.xml');

        $this->assertResponseIsSuccessful();

        $content = (string) $browser->getResponse()->getContent();

        self::assertStringContainsString('<urlset', $content);

        // Brand directory, every locale
        self::assertStringContainsString('<loc>http://localhost/en/puzzle/brands</loc>', $content);
        self::assertStringContainsString('<loc>http://localhost/puzzle/znacky</loc>', $content);
        self::assertStringContainsString('<loc>http://localhost/de/puzzle/marken</loc>', $content);
        self::assertStringContainsString('<loc>http://localhost/es/puzzles/marcas</loc>', $content);
        self::assertStringContainsString('<loc>http://localhost/fr/puzzle/marques</loc>', $content);
        self::assertStringContainsString('<loc>http://localhost/ja/' . rawurlencode('パズル') . '/' . rawurlencode('ブランド') . '</loc>', $content);

        // Brand hubs and piece-count hubs
        self::assertStringContainsString('<loc>http://localhost/en/puzzle/brand/ravensburger</loc>', $content);
        self::assertStringContainsString('<loc>http://localhost/puzzle/znacka/ravensburger</loc>', $content);
        self::assertStringContainsString('<loc>http://localhost/en/puzzle/1000-pieces</loc>', $content);
        self::assertStringContainsString('<loc>http://localhost/puzzle/1000-dilku</loc>', $content);

        // Indexable brand × pieces pages (Ravensburger 500 + 1000), every locale
        self::assertStringContainsString('<loc>http://localhost/en/puzzle/brand/ravensburger/500-pieces</loc>', $content);
        self::assertStringContainsString('<loc>http://localhost/en/puzzle/brand/ravensburger/1000-pieces</loc>', $content);
        self::assertStringContainsString('<loc>http://localhost/puzzle/znacka/ravensburger/500-dilku</loc>', $content);
        self::assertStringContainsString('<loc>http://localhost/de/puzzle/marke/ravensburger/500-teile</loc>', $content);
        self::assertStringContainsString('<loc>http://localhost/es/puzzles/marca/ravensburger/500-piezas</loc>', $content);
        self::assertStringContainsString('<loc>http://localhost/fr/puzzle/marque/ravensburger/500-pieces</loc>', $content);

        // Thin brand × pieces pages are left out: Ravensburger 300 (1 puzzle), Trefl 500 (2 puzzles)
        self::assertStringNotContainsString('/en/puzzle/brand/ravensburger/300-pieces', $content);
        self::assertStringNotContainsString('/en/puzzle/brand/trefl/500-pieces', $content);

        // Thin brand (single unapproved puzzle, no solves) must not be listed
        self::assertStringNotContainsString('unknown-brand', $content);

        // Numbered pages 2+ are discovered through the pagination links, not the sitemap
        self::assertStringNotContainsString('/page/', $content);
        self::assertStringNotContainsString('/strana/', $content);

        self::assertStringNotContainsString('xhtml:link', $content);
    }
}
