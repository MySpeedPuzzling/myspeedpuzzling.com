<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DifficultyRankingSeeding;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SitemapDifficultyControllerTest extends WebTestCase
{
    use DifficultyRankingSeeding;

    public function testSitemapIndexListsTheDifficultySitemap(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/sitemap.xml');

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('<loc>http://localhost/sitemap-difficulty.xml</loc>', (string) $browser->getResponse()->getContent());
    }

    public function testEveryExistingListIsListedInEveryLocale(): void
    {
        $browser = self::createClient();
        $container = $browser->getContainer();

        // 750 pieces: 50 rated (a list), all of them Ravensburger (a brand list too);
        // Trefl: 39 rated 1000-piece puzzles - neither 1000 pieces nor Trefl get a list
        $this->seedRatedPuzzles($container, ManufacturerFixture::MANUFACTURER_RAVENSBURGER, 750, 50, 'Ravensburger');
        $this->seedRatedPuzzles($container, ManufacturerFixture::MANUFACTURER_TREFL, 1000, 39, 'Trefl');

        $browser->request('GET', '/sitemap-difficulty.xml');

        $this->assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/xml; charset=UTF-8');

        $content = (string) $browser->getResponse()->getContent();

        $expected = [
            '/puzzle/750-dilku/nejtezsi',
            '/puzzle/750-dilku/nejlehci',
            '/en/puzzle/750-pieces/hardest',
            '/en/puzzle/750-pieces/easiest',
            '/es/puzzles/750-piezas/mas-dificiles',
            '/es/puzzles/750-piezas/mas-faciles',
            '/ja/' . rawurlencode('パズル') . '/750' . rawurlencode('ピース') . '/' . rawurlencode('難しい'),
            '/ja/' . rawurlencode('パズル') . '/750' . rawurlencode('ピース') . '/' . rawurlencode('簡単'),
            '/fr/puzzle/750-pieces/plus-difficiles',
            '/fr/puzzle/750-pieces/plus-faciles',
            '/de/puzzle/750-teile/schwierigste',
            '/de/puzzle/750-teile/leichteste',
            '/puzzle/znacka/ravensburger/nejtezsi',
            '/puzzle/znacka/ravensburger/nejlehci',
            '/en/puzzle/brand/ravensburger/hardest',
            '/en/puzzle/brand/ravensburger/easiest',
            '/es/puzzles/marca/ravensburger/mas-dificiles',
            '/es/puzzles/marca/ravensburger/mas-faciles',
            '/ja/' . rawurlencode('パズル') . '/' . rawurlencode('ブランド') . '/ravensburger/' . rawurlencode('難しい'),
            '/ja/' . rawurlencode('パズル') . '/' . rawurlencode('ブランド') . '/ravensburger/' . rawurlencode('簡単'),
            '/fr/puzzle/marque/ravensburger/plus-difficiles',
            '/fr/puzzle/marque/ravensburger/plus-faciles',
            '/de/puzzle/marke/ravensburger/schwierigste',
            '/de/puzzle/marke/ravensburger/leichteste',
        ];

        foreach ($expected as $path) {
            self::assertStringContainsString('<loc>http://localhost' . $path . '</loc>', $content);
        }

        self::assertSame(count($expected), substr_count($content, '<loc>'));
        self::assertStringNotContainsString('1000-pieces', $content);
        self::assertStringNotContainsString('trefl', $content);
        self::assertStringNotContainsString('xhtml:link', $content);
    }

    public function testSitemapIsValidWithoutAnyList(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/sitemap-difficulty.xml');

        $this->assertResponseIsSuccessful();

        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('<urlset', $content);
        self::assertStringNotContainsString('<loc>', $content);
    }
}
