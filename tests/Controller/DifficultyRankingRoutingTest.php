<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RouterInterface;

/**
 * The hardest / easiest routes share their prefixes with puzzle_detail's
 * {puzzleId} routes and with the brand / pieces hubs (+ their pagination and
 * brand x pieces pages). Each URL must land on exactly the page it names.
 */
final class DifficultyRankingRoutingTest extends KernelTestCase
{
    private const array RANKING_ROUTES = [
        'pieces_hardest_puzzles',
        'pieces_easiest_puzzles',
        'brand_hardest_puzzles',
        'brand_easiest_puzzles',
    ];

    /**
     * @return iterable<string, array{string, string, array<string, string>}>
     */
    public static function rankingUrls(): iterable
    {
        $pieces = [
            'cs' => ['/puzzle/1000-dilku/nejtezsi', '/puzzle/1000-dilku/nejlehci'],
            'en' => ['/en/puzzle/1000-pieces/hardest', '/en/puzzle/1000-pieces/easiest'],
            'es' => ['/es/puzzles/1000-piezas/mas-dificiles', '/es/puzzles/1000-piezas/mas-faciles'],
            'ja' => ['/ja/パズル/1000ピース/難しい', '/ja/パズル/1000ピース/簡単'],
            'fr' => ['/fr/puzzle/1000-pieces/plus-difficiles', '/fr/puzzle/1000-pieces/plus-faciles'],
            'de' => ['/de/puzzle/1000-teile/schwierigste', '/de/puzzle/1000-teile/leichteste'],
        ];

        foreach ($pieces as $locale => [$hardest, $easiest]) {
            yield "{$locale} pieces hardest" => [$hardest, 'pieces_hardest_puzzles', ['pieces' => '1000', 'direction' => 'hardest', '_locale' => $locale]];
            yield "{$locale} pieces easiest" => [$easiest, 'pieces_easiest_puzzles', ['pieces' => '1000', 'direction' => 'easiest', '_locale' => $locale]];
        }

        $brand = [
            'cs' => ['/puzzle/znacka/ravensburger/nejtezsi', '/puzzle/znacka/ravensburger/nejlehci'],
            'en' => ['/en/puzzle/brand/ravensburger/hardest', '/en/puzzle/brand/ravensburger/easiest'],
            'es' => ['/es/puzzles/marca/ravensburger/mas-dificiles', '/es/puzzles/marca/ravensburger/mas-faciles'],
            'ja' => ['/ja/パズル/ブランド/ravensburger/難しい', '/ja/パズル/ブランド/ravensburger/簡単'],
            'fr' => ['/fr/puzzle/marque/ravensburger/plus-difficiles', '/fr/puzzle/marque/ravensburger/plus-faciles'],
            'de' => ['/de/puzzle/marke/ravensburger/schwierigste', '/de/puzzle/marke/ravensburger/leichteste'],
        ];

        foreach ($brand as $locale => [$hardest, $easiest]) {
            yield "{$locale} brand hardest" => [$hardest, 'brand_hardest_puzzles', ['slug' => 'ravensburger', 'direction' => 'hardest', '_locale' => $locale]];
            yield "{$locale} brand easiest" => [$easiest, 'brand_easiest_puzzles', ['slug' => 'ravensburger', 'direction' => 'easiest', '_locale' => $locale]];
        }
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('rankingUrls')]
    public function testRankingUrlResolvesToItsRoute(string $path, string $route, array $parameters): void
    {
        $match = $this->match($path);

        self::assertNotNull($match, sprintf('"%s" must resolve', $path));
        self::assertSame($route, $match['_canonical_route'] ?? $match['_route']);

        foreach ($parameters as $name => $value) {
            self::assertSame($value, $match[$name] ?? null, sprintf('Parameter "%s" of "%s"', $name, $path));
        }

        // And the router generates the very same URL back (canonical, hreflang, sitemap)
        $router = $this->router();
        $generated = $router->generate($route, array_diff_key($parameters, ['direction' => true]));
        self::assertSame($path, rawurldecode($generated));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function neighbouringUrls(): iterable
    {
        yield 'pieces hub' => ['/en/puzzle/1000-pieces', 'pieces_puzzles'];
        yield 'pieces hub cs' => ['/puzzle/1000-dilku', 'pieces_puzzles'];
        yield 'brand hub' => ['/en/puzzle/brand/ravensburger', 'brand_puzzles'];
        yield 'brand hub of a brand called like a direction' => ['/en/puzzle/brand/hardest', 'brand_puzzles'];
        yield 'puzzle detail' => ['/en/puzzle/' . PuzzleFixture::PUZZLE_500_01, 'puzzle_detail'];
        yield 'puzzle detail cs' => ['/puzzle/' . PuzzleFixture::PUZZLE_500_01, 'puzzle_detail'];
        yield 'puzzle qr code' => ['/en/puzzle/' . PuzzleFixture::PUZZLE_500_01 . '/qr-code', 'puzzle_qr_code_modal'];
    }

    #[DataProvider('neighbouringUrls')]
    public function testNeighbouringUrlsKeepTheirRoutes(string $path, string $route): void
    {
        $match = $this->match($path);

        self::assertNotNull($match, sprintf('"%s" must resolve', $path));
        self::assertSame($route, $match['_canonical_route'] ?? $match['_route']);
    }

    /**
     * Hub pagination and brand x piece-count pages (docs/features/seo/implementation-plan-2026-10.md,
     * WS-F1) live next to the rankings - none of their URLs may be read as a ranking.
     *
     * @return iterable<string, array{string}>
     */
    public static function catalogueUrls(): iterable
    {
        yield 'pieces hub page 2' => ['/en/puzzle/1000-pieces/page/2'];
        yield 'pieces hub page 2 cs' => ['/puzzle/1000-dilku/strana/2'];
        yield 'brand hub page 2' => ['/en/puzzle/brand/ravensburger/page/2'];
        yield 'brand x pieces' => ['/en/puzzle/brand/ravensburger/1000-pieces'];
        yield 'brand x pieces cs' => ['/puzzle/znacka/ravensburger/1000-dilku'];
        yield 'brand x pieces de' => ['/de/puzzle/marke/ravensburger/1000-teile'];
        yield 'brand x pieces page 2' => ['/en/puzzle/brand/ravensburger/1000-pieces/page/2'];
    }

    #[DataProvider('catalogueUrls')]
    public function testCatalogueUrlsAreNeverRankings(string $path): void
    {
        $match = $this->match($path);
        $route = $match === null ? null : ($match['_canonical_route'] ?? $match['_route']);

        self::assertNotContains($route, self::RANKING_ROUTES, sprintf('"%s" must not resolve to a ranking', $path));
    }

    /**
     * @return null|array<mixed>
     */
    private function match(string $path): null|array
    {
        try {
            return $this->router()->match($path);
        } catch (ResourceNotFoundException) {
            return null;
        }
    }

    private function router(): RouterInterface
    {
        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');

        return $router;
    }
}
