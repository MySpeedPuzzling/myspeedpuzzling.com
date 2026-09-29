<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DifficultyRankingSeeding;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\MetricConfidence;
use SpeedPuzzling\Web\Value\PuzzleStatisticsData;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PiecesDifficultyRankingControllerTest extends WebTestCase
{
    use DifficultyRankingSeeding;
    use DifficultyRankingPageAssertions;

    private const string RAVENSBURGER = ManufacturerFixture::MANUFACTURER_RAVENSBURGER;

    public function testPiecesCountWithoutHubReturns404(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle/123-pieces/hardest');
        $this->assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/puzzle/123-pieces/easiest');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testPiecesCountWithTooFewRatedPuzzlesReturns404(): void
    {
        $browser = self::createClient();
        $container = $browser->getContainer();

        // 49 rated puzzles - one short of a list; the ones below do not count
        $this->seedRatedPuzzles($container, self::RAVENSBURGER, 750, 49, 'Almost');
        $this->seedRatedPuzzles($container, self::RAVENSBURGER, 750, 3, 'Low confidence', confidence: MetricConfidence::Low);
        $this->seedRatedPuzzle($container, self::RAVENSBURGER, 750, 'Unapproved', 1.5, approved: false);
        $this->seedRatedPuzzle($container, self::RAVENSBURGER, 750, 'Hidden', 1.5, hideUntil: new DateTimeImmutable('+1 year'));

        $browser->request('GET', '/en/puzzle/750-pieces/hardest');
        $this->assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/puzzle/750-pieces/easiest');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAnonymousVisitorSeesTheTwentyFiveHardestNamesInOrder(): void
    {
        $browser = self::createClient();
        $this->seedList($browser, 55);

        $crawler = $browser->request('GET', '/en/puzzle/750-pieces/hardest');

        $this->assertResponseIsSuccessful();
        self::assertSame('25 Hardest 750-Piece Puzzles (Real Data) – MySpeedPuzzling', $crawler->filter('title')->text());
        self::assertSame('The 25 Hardest 750-Piece Puzzles', $crawler->filter('h1')->text());
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame('http://localhost/en/puzzle/750-pieces/hardest', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame('http://localhost/de/puzzle/750-teile/schwierigste', $crawler->filter('link[rel="alternate"][hreflang="de"]')->attr('href'));

        self::assertSame(self::expectedNames(1, 25), $this->rankedNames($crawler));
        self::assertStringNotContainsString('Rated 026', (string) $browser->getResponse()->getContent());

        // Public data per row: rank, brand (a piece-count list does not repeat the piece count)
        $firstRow = $crawler->filter('#difficulty-ranking > li')->first();
        self::assertSame('1', $firstRow->filter('.difficulty-rank')->text());
        self::assertStringContainsString('Ravensburger', $firstRow->text());
        self::assertSame('25', $crawler->filter('#difficulty-ranking > li')->last()->filter('.difficulty-rank')->text());

        // Hardest / easiest toggle: this list is the current one, the other one a link
        $toggle = $crawler->filter('main nav.btn-group');
        self::assertSame('Hardest', $toggle->filter('[aria-current="page"]')->text());
        self::assertSame('Easiest', $toggle->filter('a[href="/en/puzzle/750-pieces/easiest"]')->text());

        // Cross-links: the opposite list, the hub, the methodology, other lists of the same
        // direction (all 55 are Ravensburger - enough for a brand list) but never itself
        self::assertCount(1, $crawler->filter('main a[href="/en/puzzle/750-pieces/easiest"]'));
        self::assertGreaterThan(0, $crawler->filter('main a[href="/en/puzzle/750-pieces"]')->count());
        self::assertCount(1, $crawler->filter('main a[href="/en/methodology"]'));
        self::assertCount(1, $crawler->filter('main a[href="/en/puzzle/brand/ravensburger/hardest"]'));
        self::assertCount(0, $crawler->filter('main a[href="/en/puzzle/750-pieces/hardest"]'));

        // Locked difficulty per row + the members call to action
        self::assertCount(25, $crawler->filter('#difficulty-ranking button[data-bs-target="#membersExclusiveModal"]'));
        self::assertStringContainsString('Full ranking of 55 puzzles with difficulty scores', $crawler->filter('main')->text());
    }

    public function testTheListComesBeforeTheExplanation(): void
    {
        $browser = self::createClient();
        $this->seedList($browser, 55);

        $crawler = $browser->request('GET', '/en/puzzle/750-pieces/hardest');

        $this->assertResponseIsSuccessful();

        // Usability first: one short lead under the heading, the ranking right after it,
        // the longer "how it works" text only below the list
        $main = $crawler->filter('main')->html();
        $ranking = strpos($main, 'id="difficulty-ranking"');
        $explanation = strpos($main, 'id="how-the-ranking-works"');
        self::assertNotFalse($ranking);
        self::assertNotFalse($explanation);
        self::assertLessThan($explanation, $ranking);

        $lead = $crawler->filter('h1 + p')->text();
        self::assertSame('Ranked by real solve times logged on MySpeedPuzzling – not by opinions or the difficulty printed on the box.', $lead);
    }

    public function testRowShowsThePublicMedianSoloTimeAndSolves(): void
    {
        $browser = self::createClient();
        $this->seedList($browser, 50);
        $this->seedRatedPuzzle(
            $browser->getContainer(),
            self::RAVENSBURGER,
            750,
            'With statistics',
            4.2,
            statistics: new PuzzleStatisticsData(totalCount: 60, soloCount: 57, medianTimeSolo: 3723),
        );

        $crawler = $browser->request('GET', '/en/puzzle/750-pieces/hardest');

        $this->assertResponseIsSuccessful();
        $firstRow = $crawler->filter('#difficulty-ranking > li')->first()->text();
        self::assertStringContainsString('With statistics', $firstRow);
        self::assertStringContainsString('Median 1h 2min', $firstRow);
        self::assertStringContainsString('57 solo solves', $firstRow);
    }

    public function testEasiestListStartsWithTheLowestScore(): void
    {
        $browser = self::createClient();
        $this->seedList($browser, 55);

        $crawler = $browser->request('GET', '/en/puzzle/750-pieces/easiest');

        $this->assertResponseIsSuccessful();
        self::assertSame('The 25 Easiest 750-Piece Puzzles', $crawler->filter('h1')->text());
        self::assertSame(array_reverse(self::expectedNames(31, 55)), $this->rankedNames($crawler));
        self::assertCount(1, $crawler->filter('main a[href="/en/puzzle/750-pieces/hardest"]'));
    }

    /**
     * @return iterable<string, array{null|string}>
     */
    public static function nonMembers(): iterable
    {
        yield 'anonymous' => [null];
        yield 'signed in without membership' => [PlayerFixture::PLAYER_REGULAR];
    }

    #[DataProvider('nonMembers')]
    public function testPublicHtmlNeverContainsDifficultyScoresOrTiers(null|string $playerId): void
    {
        $browser = self::createClient();

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        $this->seedList($browser, 55);

        foreach (['/en/puzzle/750-pieces/hardest', '/en/puzzle/750-pieces/easiest'] as $url) {
            $crawler = $browser->request('GET', $url);
            $this->assertResponseIsSuccessful();

            $this->assertNoDifficultyIn($crawler, (string) $browser->getResponse()->getContent(), self::seededScores(55), 'en');
        }
    }

    /**
     * Japanese is left out on purpose: its natural words for hard / easy (難しい,
     * 簡単) are both the page titles and tier names - no per-puzzle label there.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function otherLocalesHardestAndEasiest(): iterable
    {
        yield 'cs hardest' => ['cs', '/puzzle/750-dilku/nejtezsi'];
        yield 'cs easiest' => ['cs', '/puzzle/750-dilku/nejlehci'];
        yield 'de hardest' => ['de', '/de/puzzle/750-teile/schwierigste'];
        yield 'de easiest' => ['de', '/de/puzzle/750-teile/leichteste'];
        yield 'fr hardest' => ['fr', '/fr/puzzle/750-pieces/plus-difficiles'];
        yield 'fr easiest' => ['fr', '/fr/puzzle/750-pieces/plus-faciles'];
        yield 'es hardest' => ['es', '/es/puzzles/750-piezas/mas-dificiles'];
        yield 'es easiest' => ['es', '/es/puzzles/750-piezas/mas-faciles'];
    }

    #[DataProvider('otherLocalesHardestAndEasiest')]
    public function testPublicHtmlNeverContainsDifficultyInOtherLocales(string $locale, string $url): void
    {
        $browser = self::createClient();
        $this->seedList($browser, 55);

        $crawler = $browser->request('GET', $url);

        $this->assertResponseIsSuccessful();
        $this->assertNoDifficultyIn($crawler, (string) $browser->getResponse()->getContent(), self::seededScores(55), $locale);
    }

    public function testMemberSeesDifficultyForUpToHundredPuzzles(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->seedList($browser, 105);

        $crawler = $browser->request('GET', '/en/puzzle/750-pieces/hardest');

        $this->assertResponseIsSuccessful();
        self::assertSame('The 100 Hardest 750-Piece Puzzles', $crawler->filter('h1')->text());
        self::assertSame(self::expectedNames(1, 100), $this->rankedNames($crawler));

        $html = (string) $browser->getResponse()->getContent();
        $main = $crawler->filter('main')->text();

        // #1 = 1.9137 -> Very Hard, 91% harder; #99 = 0.8063 -> Easy, 19% easier
        self::assertStringContainsString('badge-tier-veryhard', $html);
        self::assertStringContainsString('badge-tier-easy', $html);
        self::assertStringContainsString($this->translateTo(DifficultyTier::VeryHard->translationKey()), $main);
        self::assertStringContainsString($this->translateTo(DifficultyTier::Easy->translationKey()), $main);
        self::assertStringContainsString('91% harder than average', $main);
        self::assertStringContainsString('19% easier than average', $main);
        self::assertStringContainsString('Top 100 of 105 rated puzzles', $main);

        self::assertStringNotContainsString('Full ranking of', $main);
        self::assertCount(0, $crawler->filter('#difficulty-ranking button[data-bs-target="#membersExclusiveModal"]'));
    }

    public function testStructuredDataListsThePublicTwentyFiveForEverybody(): void
    {
        $browser = self::createClient();
        $ids = $this->seedList($browser, 105);

        $crawler = $browser->request('GET', '/en/puzzle/750-pieces/hardest');
        $this->assertResponseIsSuccessful();
        $this->assertItemListOfTwentyFive($crawler, $ids);

        $breadcrumb = $this->jsonLd($crawler, 'BreadcrumbList');
        self::assertIsArray($breadcrumb['itemListElement']);
        self::assertCount(3, $breadcrumb['itemListElement']);
        self::assertSame([
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Jigsaw Puzzle Database', 'item' => 'http://localhost/en/puzzle'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => '750-Piece Puzzles', 'item' => 'http://localhost/en/puzzle/750-pieces'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => 'The 25 Hardest 750-Piece Puzzles'],
        ], $breadcrumb['itemListElement']);

        // Members see a longer ranking, the structured data stays the public one
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/en/puzzle/750-pieces/hardest');
        $this->assertResponseIsSuccessful();
        self::assertCount(100, $crawler->filter('#difficulty-ranking > li'));
        $this->assertItemListOfTwentyFive($crawler, $ids);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function localizedUrls(): iterable
    {
        yield 'cs hardest' => ['/puzzle/750-dilku/nejtezsi', '25 nejtěžších puzzlí s 750 dílky'];
        yield 'cs easiest' => ['/puzzle/750-dilku/nejlehci', '25 nejlehčích puzzlí s 750 dílky'];
        yield 'de hardest' => ['/de/puzzle/750-teile/schwierigste', 'Die 25 schwierigsten 750-teiligen Puzzles'];
        yield 'de easiest' => ['/de/puzzle/750-teile/leichteste', 'Die 25 leichtesten 750-teiligen Puzzles'];
        yield 'fr hardest' => ['/fr/puzzle/750-pieces/plus-difficiles', 'Les 25 puzzles de 750 pièces les plus difficiles'];
        yield 'fr easiest' => ['/fr/puzzle/750-pieces/plus-faciles', 'Les 25 puzzles de 750 pièces les plus faciles'];
        yield 'es hardest' => ['/es/puzzles/750-piezas/mas-dificiles', 'Los 25 puzzles de 750 piezas más difíciles'];
        yield 'es easiest' => ['/es/puzzles/750-piezas/mas-faciles', 'Los 25 puzzles de 750 piezas más fáciles'];
        yield 'ja hardest' => ['/ja/' . rawurlencode('パズル') . '/750' . rawurlencode('ピース') . '/' . rawurlencode('難しい'), '難しい750ピースパズル TOP25'];
        yield 'ja easiest' => ['/ja/' . rawurlencode('パズル') . '/750' . rawurlencode('ピース') . '/' . rawurlencode('簡単'), '簡単な750ピースパズル TOP25'];
    }

    #[DataProvider('localizedUrls')]
    public function testLocalizedUrl(string $url, string $expectedHeading): void
    {
        $browser = self::createClient();
        $this->seedList($browser, 55);

        $crawler = $browser->request('GET', $url);

        $this->assertResponseIsSuccessful();
        self::assertSame($expectedHeading, $crawler->filter('h1')->text());
        self::assertCount(25, $crawler->filter('#difficulty-ranking > li'));
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }

    /**
     * Seeds $count rated 750-piece puzzles, "Rated 001" the hardest (scores: seededScores()).
     *
     * @return list<string> puzzle ids, hardest first
     */
    private function seedList(KernelBrowser $browser, int $count): array
    {
        return $this->seedRatedPuzzles($browser->getContainer(), self::RAVENSBURGER, 750, $count, 'Rated');
    }

    /**
     * The scores seedRatedPuzzles() gives by default, hardest first.
     *
     * @return list<float>
     */
    private static function seededScores(int $count): array
    {
        return array_map(static fn (int $i): float => round(1.9137 - $i * 0.0113, 4), range(0, $count - 1));
    }

    /**
     * @return list<string>
     */
    private static function expectedNames(int $from, int $to): array
    {
        return array_map(static fn (int $position): string => sprintf('Rated %03d', $position), range($from, $to));
    }

    /**
     * @param list<string> $ids hardest first
     */
    private function assertItemListOfTwentyFive(Crawler $crawler, array $ids): void
    {
        $itemList = $this->jsonLd($crawler, 'ItemList');

        self::assertSame('The 25 Hardest 750-Piece Puzzles', $itemList['name']);
        self::assertSame(25, $itemList['numberOfItems']);
        self::assertIsArray($itemList['itemListElement']);
        self::assertCount(25, $itemList['itemListElement']);

        foreach ($itemList['itemListElement'] as $index => $element) {
            self::assertSame([
                '@type' => 'ListItem',
                'position' => $index + 1,
                'url' => 'http://localhost/en/puzzle/' . $ids[$index],
                'name' => sprintf('Ravensburger Rated %03d', $index + 1),
            ], $element);
        }
    }
}
