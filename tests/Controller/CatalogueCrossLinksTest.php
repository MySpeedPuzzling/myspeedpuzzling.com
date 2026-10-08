<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Controller\PiecesPuzzlesController;
use SpeedPuzzling\Web\Results\DifficultyRankingBrand;
use SpeedPuzzling\Web\Results\DifficultyRankingsAvailability;
use SpeedPuzzling\Web\Tests\CatalogueTestData;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\TagFixture;
use SpeedPuzzling\Web\Tests\PinsSolveTimeDistributions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Links between the catalogue pages (docs/features/seo/implementation-plan-2026-10.md, WS-F2): the line
 * under a hub's list (the size's "how long" guide, the hardest / easiest lists), the "Browse by brand /
 * by piece count" block of the puzzle database and the brand link of every puzzle card.
 */
final class CatalogueCrossLinksTest extends WebTestCase
{
    use CatalogueTestData;
    use PinsSolveTimeDistributions;

    protected function tearDown(): void
    {
        if (self::$booted) {
            self::clearCatalogueStatsCache(self::getContainer());
        }

        parent::tearDown();
    }

    public function testPiecesHubLinksItsGuideAndDifficultyListsUnderTheList(): void
    {
        $browser = self::createClient();
        self::pinSolveTimeDistributions();
        self::pinDifficultyLists([500], []);

        $crawler = $browser->request('GET', '/en/puzzle/500-pieces');

        $this->assertResponseIsSuccessful();
        $links = $crawler->filter('#main-content p.catalogue-more-links a');
        self::assertSame(
            ['/en/guides/how-long-does-a-500-piece-puzzle-take', '/en/puzzle/500-pieces/hardest', '/en/puzzle/500-pieces/easiest'],
            $links->extract(['href']),
        );
        self::assertSame(
            ['How long does a 500-piece puzzle take?', 'Hardest 500-piece puzzles', 'Easiest 500-piece puzzles'],
            $links->each(static fn (Crawler $link): string => $link->text()),
        );
        self::assertSame(['en', null, null], array_map(
            static fn (Crawler $link): null|string => $link->attr('hreflang'),
            $links->each(static fn (Crawler $link): Crawler => $link),
        ));

        // Under the list, never above it
        $html = (string) $browser->getResponse()->getContent();
        self::assertGreaterThan(strrpos($html, 'puzzle-list-item'), strpos($html, 'catalogue-more-links'));
    }

    public function testPiecesHubWithoutAGuideOrListsHasNoLine(): void
    {
        $browser = self::createClient();

        // 750 pieces has no guide, and the fixtures have no rated puzzles - no lists either
        $crawler = $browser->request('GET', '/en/puzzle/750-pieces');

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('p.catalogue-more-links'));
    }

    public function testBrandHubLinksItsDifficultyLists(): void
    {
        $browser = self::createClient();
        self::clearCatalogueStatsCache($browser->getContainer());
        self::pinDifficultyLists([], [self::ravensburgerLists()]);

        $crawler = $browser->request('GET', '/en/puzzle/brand/ravensburger');

        $this->assertResponseIsSuccessful();
        $links = $crawler->filter('#main-content p.catalogue-more-links a');
        self::assertSame(['/en/puzzle/brand/ravensburger/hardest', '/en/puzzle/brand/ravensburger/easiest'], $links->extract(['href']));
        self::assertSame(
            ['Hardest Ravensburger puzzles', 'Easiest Ravensburger puzzles'],
            $links->each(static fn (Crawler $link): string => $link->text()),
        );
    }

    public function testBrandPiecesPageLinksTheGuideAndBothLists(): void
    {
        $browser = self::createClient();
        self::clearCatalogueStatsCache($browser->getContainer());
        self::pinDifficultyLists([1000], [self::ravensburgerLists()]);

        // The 1000-piece guide is always live
        $crawler = $browser->request('GET', '/en/puzzle/brand/ravensburger/1000-pieces');

        $this->assertResponseIsSuccessful();
        self::assertSame(
            [
                '/en/guides/how-long-does-a-1000-piece-puzzle-take',
                '/en/puzzle/1000-pieces/hardest',
                '/en/puzzle/1000-pieces/easiest',
                '/en/puzzle/brand/ravensburger/hardest',
                '/en/puzzle/brand/ravensburger/easiest',
            ],
            $crawler->filter('#main-content p.catalogue-more-links a')->extract(['href']),
        );
    }

    public function testCzechLineIsTranslated(): void
    {
        $browser = self::createClient();
        self::pinSolveTimeDistributions();
        self::pinDifficultyLists([500], []);

        $crawler = $browser->request('GET', '/puzzle/500-dilku');

        $this->assertResponseIsSuccessful();
        self::assertSame(
            ['Jak dlouho se skládá puzzle s 500 dílky? (v angličtině)', 'Nejtěžší puzzle s 500 dílky', 'Nejlehčí puzzle s 500 dílky'],
            $crawler->filter('#main-content p.catalogue-more-links a')->each(static fn (Crawler $link): string => $link->text()),
        );
        self::assertSame(
            ['/en/guides/how-long-does-a-500-piece-puzzle-take', '/puzzle/500-dilku/nejtezsi', '/puzzle/500-dilku/nejlehci'],
            $crawler->filter('#main-content p.catalogue-more-links a')->extract(['href']),
        );
    }

    public function testPuzzleDatabaseBrowsesByBrandAndPieceCount(): void
    {
        $browser = self::createClient();
        self::clearCatalogueStatsCache($browser->getContainer());

        $crawler = $browser->request('GET', '/en/puzzle');

        $this->assertResponseIsSuccessful();
        $browse = $crawler->filter('section.puzzle-catalogue-browse');
        self::assertCount(1, $browse);

        // The database in numbers
        self::assertCount(4, $browse->filter('ul.catalogue-numbers li'));

        // The most solved brands' hubs - Ravensburger and Trefl in the fixtures - and the A–Z directory
        $hrefs = $browse->filter('a')->extract(['href']);
        self::assertContains('/en/puzzle/brand/ravensburger', $hrefs);
        self::assertContains('/en/puzzle/brand/trefl', $hrefs);
        self::assertContains('/en/puzzle/brands', $hrefs);

        // Every pieces hub and the table of every size
        foreach (PiecesPuzzlesController::ALLOWED_PIECES as $pieces) {
            self::assertContains('/en/puzzle/' . $pieces . '-pieces', $hrefs);
        }
        self::assertSame('en', $browse->filter('a[href="/en/guides/average-puzzle-time-by-piece-count"]')->attr('hreflang'));

        // Under the list
        $html = (string) $browser->getResponse()->getContent();
        self::assertGreaterThan(strrpos($html, 'puzzle-list-item'), strpos($html, 'puzzle-catalogue-browse'));
    }

    public function testPuzzleCardsLinkTheirBrandHub(): void
    {
        $browser = self::createClient();
        self::clearCatalogueStatsCache($browser->getContainer());

        // A pieces hub lists several brands
        $crawler = $browser->request('GET', '/en/puzzle/500-pieces');
        $this->assertResponseIsSuccessful();
        $brandLinks = self::cardBrandLinks($crawler);
        self::assertContains('/en/puzzle/brand/ravensburger', $brandLinks);
        self::assertContains('/en/puzzle/brand/trefl', $brandLinks);

        // A brand × pieces page links up to its brand hub
        $crawler = $browser->request('GET', '/en/puzzle/brand/ravensburger/500-pieces');
        self::assertSame(['/en/puzzle/brand/ravensburger'], array_values(array_unique(self::cardBrandLinks($crawler))));

        // ... but a brand hub's own cards do not link the page itself
        $crawler = $browser->request('GET', '/en/puzzle/brand/ravensburger');
        self::assertGreaterThan(0, $crawler->filter('.puzzle-list-item')->count());
        self::assertSame([], self::cardBrandLinks($crawler));

        // The puzzle database, its "load more" pages and other languages
        $crawler = $browser->request('GET', '/en/puzzle');
        self::assertContains('/en/puzzle/brand/ravensburger', self::cardBrandLinks($crawler));

        $browser->request('GET', '/en/puzzle-search-items?offset=0');
        /** @var array{html: string} $items */
        $items = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertContains('/en/puzzle/brand/ravensburger', self::cardBrandLinks(new Crawler($items['html'])));

        $crawler = $browser->request('GET', '/puzzle/500-dilku');
        self::assertContains('/puzzle/znacka/ravensburger', self::cardBrandLinks($crawler));

        // An event page listing its tagged puzzles (one outside its rounds - a round's puzzles sit in the round)
        self::getContainer()->get(Connection::class)->executeStatement(
            'INSERT INTO tag_puzzle (tag_id, puzzle_id) VALUES (:tagId, :puzzleId)',
            ['tagId' => TagFixture::TAG_WJPC, 'puzzleId' => PuzzleFixture::PUZZLE_1000_05],
        );
        $crawler = $browser->request('GET', '/en/events/wjpc-2024');
        $this->assertResponseIsSuccessful();
        self::assertSame(['/en/puzzle/brand/ravensburger'], self::cardBrandLinks($crawler));
    }

    /**
     * @return list<string>
     */
    private static function cardBrandLinks(Crawler $crawler): array
    {
        return $crawler->filter('.puzzle-list-item .manufacturer-name a')->each(
            static fn (Crawler $link): string => (string) $link->attr('href'),
        );
    }

    private static function ravensburgerLists(): DifficultyRankingBrand
    {
        return new DifficultyRankingBrand(
            brandId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            brandName: 'Ravensburger',
            slug: 'ravensburger',
            ratedPuzzlesCount: 45,
        );
    }

    /**
     * Which hardest / easiest lists exist is decided by PuzzleDifficultyRankings (WS-H) from rated puzzles the
     * fixtures do not have - pinned here in its pool, an in-memory one in the test env that the client's first
     * request shares (same kernel). Pin after createClient(), before the first request.
     *
     * @param list<int> $piecesCounts
     * @param list<DifficultyRankingBrand> $brands
     */
    private static function pinDifficultyLists(array $piecesCounts, array $brands): void
    {
        $brandsBySlug = [];
        foreach ($brands as $brand) {
            $brandsBySlug[$brand->slug] = $brand;
        }

        $availability = new DifficultyRankingsAvailability(
            ratedPuzzlesPerPieces: array_fill_keys($piecesCounts, 60),
            brands: $brandsBySlug,
        );

        /** @var CacheInterface $cache */
        $cache = self::getContainer()->get('difficulty_rankings_cache');
        $cache->delete('availability');
        $cache->get('availability', static fn (): DifficultyRankingsAvailability => $availability);
    }
}
