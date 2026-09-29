<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Component\PuzzleTimes;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\LeaderboardSeeding;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The leaderboard renders only its top rows; "show more" / "show all" reveal the rest, while
 * filters, ranks, statistics and the chart keep working on the whole list
 * (docs/features/seo/implementation-plan-2026-10.md, WS-C).
 *
 * Solo leaderboard of PUZZLE_500_01 - best time per player, PLAYER_PRIVATE is hidden from these viewers:
 *   1. PLAYER_ADMIN 1200 s, 2. PLAYER_REGULAR 1750 s, 3. PLAYER_WITH_STRIPE 2100 s, 4. PLAYER_WITH_FAVORITES 3000 s
 */
final class PuzzleTimesLeaderboardLimitTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use LeaderboardSeeding;

    public function testOnlyTheTopRowsAreRenderedWhileStatisticsDescribeTheWholeLeaderboard(): void
    {
        $client = self::createClient();

        $crawler = $this->mountSoloLeaderboard($client, PuzzleFixture::PUZZLE_500_01, 500, limit: 2)->render()->crawler();

        self::assertSame([PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR], $this->rowKeys($crawler));
        self::assertSame(['1.', '2.'], $this->ranks($crawler));
        self::assertCount(0, $crawler->filter('tr.leaderboard-gap'));

        // Tab count and median describe all 4 rows - the median of the 2 visible ones would be 00:24:35
        self::assertStringContainsString('Solo (4)', $crawler->filter('.puzzle-category-types')->text());
        self::assertStringContainsString('00:32:05', $crawler->text());

        self::assertSame('Show 2 more', $this->buttonText($crawler, 'showMore'));
        self::assertSame('Show all (4)', $this->buttonText($crawler, 'showAll'));
    }

    public function testNoButtonsWhenEveryRowFits(): void
    {
        $client = self::createClient();

        $crawler = $this->mountSoloLeaderboard($client, PuzzleFixture::PUZZLE_500_01, 500)->render()->crawler();

        self::assertCount(4, $this->rowKeys($crawler));
        self::assertCount(0, $crawler->filter('button[data-live-action-param="showMore"]'));
        self::assertCount(0, $crawler->filter('button[data-live-action-param="showAll"]'));
    }

    public function testShowMoreRevealsTheNextRowsAndShowAllTheRest(): void
    {
        $client = self::createClient();
        $solvers = $this->seedSoloSolvers(PuzzleFixture::PUZZLE_1000_04, 250);
        $component = $this->mountSoloLeaderboard($client, PuzzleFixture::PUZZLE_1000_04, 1000);

        $crawler = $component->render()->crawler();
        self::assertSame(array_slice($solvers, 0, PuzzleTimes::DEFAULT_LIMIT), $this->rowKeys($crawler));
        self::assertSame('Show 100 more', $this->buttonText($crawler, 'showMore'));
        self::assertSame('Show all (250)', $this->buttonText($crawler, 'showAll'));

        $crawler = $component->call('showMore')->render()->crawler();
        self::assertSame(array_slice($solvers, 0, 200), $this->rowKeys($crawler));
        self::assertSame('200.', $this->ranks($crawler)[199]);
        self::assertSame('Show 50 more', $this->buttonText($crawler, 'showMore'));
        self::assertSame('Show all (250)', $this->buttonText($crawler, 'showAll'));

        $crawler = $component->call('showAll')->render()->crawler();
        self::assertSame($solvers, $this->rowKeys($crawler));
        self::assertCount(0, $crawler->filter('button[data-live-action-param="showMore"]'));
        self::assertCount(0, $crawler->filter('button[data-live-action-param="showAll"]'));
        self::assertSame(250, $this->limitOf($component));
    }

    public function testFilteredLeaderboardIsSlicedFromItsTopAgain(): void
    {
        $client = self::createClient();
        // 150 first attempts: the "first attempts only" filter keeps all of them
        $solvers = $this->seedSoloSolvers(PuzzleFixture::PUZZLE_1000_04, 150, firstAttempt: true);
        $component = $this->mountSoloLeaderboard($client, PuzzleFixture::PUZZLE_1000_04, 1000);

        self::assertCount(150, $this->rowKeys($component->call('showAll')->render()->crawler()));

        $crawler = $component->set('onlyFirstTries', true)->render()->crawler();

        self::assertSame(array_slice($solvers, 0, PuzzleTimes::DEFAULT_LIMIT), $this->rowKeys($crawler));
        self::assertSame('Show 50 more', $this->buttonText($crawler, 'showMore'));
        self::assertSame('Show all (150)', $this->buttonText($crawler, 'showAll'));
    }

    /**
     * @return iterable<string, array{string, bool|string}>
     */
    public static function provideFilters(): iterable
    {
        yield 'first attempts only' => ['onlyFirstTries', true];
        yield 'unboxed only' => ['onlyUnboxed', true];
        yield 'favorite players only' => ['onlyFavoritePlayers', true];
        yield 'my pairs / teams only' => ['onlyMyTeams', true];
        yield 'country' => ['country', 'cz'];
    }

    #[DataProvider('provideFilters')]
    public function testChangingAnyFilterResetsTheLimit(string $filter, bool|string $value): void
    {
        $client = self::createClient();
        $component = $this->mountSoloLeaderboard($client, PuzzleFixture::PUZZLE_500_01, 500, limit: 2);

        $component->call('showMore');
        self::assertSame(2 + PuzzleTimes::DEFAULT_LIMIT, $this->limitOf($component));

        $component->set($filter, $value);
        self::assertSame(PuzzleTimes::DEFAULT_LIMIT, $this->limitOf($component));
    }

    public function testSwitchingTheCategoryResetsTheLimit(): void
    {
        $client = self::createClient();
        $component = $this->mountSoloLeaderboard($client, PuzzleFixture::PUZZLE_500_01, 500, limit: 2);

        $component->call('showMore');

        // Clicking the tab that is already open keeps what the visitor has revealed
        $component->call('changeResultsCategory', ['category' => 'solo']);
        self::assertSame(2 + PuzzleTimes::DEFAULT_LIMIT, $this->limitOf($component));

        $component->call('changeResultsCategory', ['category' => 'duo']);
        self::assertSame(PuzzleTimes::DEFAULT_LIMIT, $this->limitOf($component));
    }

    public function testOwnRowBeyondTheLimitFollowsAGapWithItsRealRank(): void
    {
        // PLAYER_WITH_STRIPE is a member (sees the chart) and ranks 3rd of 4
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->mountSoloLeaderboard($client, PuzzleFixture::PUZZLE_500_01, 500, limit: 2)->render()->crawler();

        self::assertSame(
            [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE],
            $this->rowKeys($crawler),
        );
        self::assertSame(['1.', '2.', '3.'], $this->ranks($crawler));

        // The "⋯" row sits right above the viewer's own row, which stays the "Jump to me" target
        $gap = $crawler->filter('tr.leaderboard-gap');
        self::assertCount(1, $gap);
        self::assertSame('leaderboard-row-' . PlayerFixture::PLAYER_WITH_STRIPE, $gap->nextAll()->first()->attr('id'));
        self::assertStringContainsString('table-active-player', (string) $gap->nextAll()->first()->attr('class'));
        self::assertSame(
            '#leaderboard-row-' . PlayerFixture::PLAYER_WITH_STRIPE,
            $crawler->filter('a[href^="#leaderboard-row-"]')->attr('href'),
        );
        self::assertStringContainsString('Rank 3 of 4', $crawler->text());

        // The chart still plots everybody, not only the visible rows
        $chartView = $crawler->filter('canvas[data-symfony--ux-chartjs--chart-view-value]')->attr('data-symfony--ux-chartjs--chart-view-value');
        self::assertNotNull($chartView);
        $chart = json_decode($chartView, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($chart);
        self::assertIsArray($chart['data']);
        self::assertIsArray($chart['data']['labels']);
        self::assertCount(4, $chart['data']['labels']);

        self::assertSame('Show 2 more', $this->buttonText($crawler, 'showMore'));
    }

    public function testNoGapWhenTheOwnRowIsAmongTheVisibleRows(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->mountSoloLeaderboard($client, PuzzleFixture::PUZZLE_500_01, 500, limit: 2)->render()->crawler();

        self::assertSame([PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR], $this->rowKeys($crawler));
        self::assertCount(0, $crawler->filter('tr.leaderboard-gap'));
    }

    public function testTiedTimesShareTheRankOfTheRowAbove(): void
    {
        $client = self::createClient();
        $this->tieThreePlayersForSecondPlace();

        $crawler = $this->mountSoloLeaderboard($client, PuzzleFixture::PUZZLE_500_01, 500)->render()->crawler();

        // Equal times, the older one first
        self::assertSame(
            [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_REGULAR],
            $this->rowKeys($crawler),
        );
        self::assertSame(['1.', '2.', '2.', '2.'], $this->ranks($crawler));
    }

    public function testOwnRowBeyondTheLimitKeepsItsTiedRank(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_REGULAR);
        $this->tieThreePlayersForSecondPlace();

        $crawler = $this->mountSoloLeaderboard($client, PuzzleFixture::PUZZLE_500_01, 500, limit: 2)->render()->crawler();

        self::assertSame(
            [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_REGULAR],
            $this->rowKeys($crawler),
        );
        self::assertSame(['1.', '2.', '2.'], $this->ranks($crawler));
        self::assertCount(1, $crawler->filter('tr.leaderboard-gap'));
    }

    public function testChromeIsKeptOutOfSearchSnippets(): void
    {
        $client = self::createClient();

        $crawler = $this->mountSoloLeaderboard($client, PuzzleFixture::PUZZLE_500_01, 500, limit: 2)->render()->crawler();

        self::assertCount(1, $crawler->filter('div.puzzle-category-types[data-nosnippet]'));
        // The filters row (the members' "Filters" button for guests)
        self::assertCount(1, $crawler->filter('div[data-nosnippet] .bi-filter'));
        self::assertCount(1, $crawler->filter('div[data-nosnippet] > button[data-live-action-param="showMore"]'));
        self::assertCount(1, $crawler->filter('div[data-nosnippet] > button[data-live-action-param="showAll"]'));
    }

    private function mountSoloLeaderboard(KernelBrowser $client, string $puzzleId, int $piecesCount, null|int $limit = null): TestLiveComponent
    {
        $data = [
            'puzzleId' => $puzzleId,
            'piecesCount' => $piecesCount,
        ];

        if ($limit !== null) {
            $data['limit'] = $limit;
        }

        $component = $this->createLiveComponent('PuzzleTimes', $data, $client);
        $component->setRouteLocale('en');

        return $component;
    }

    /**
     * PLAYER_WITH_FAVORITES (TIME_04, 7 days ago) and PLAYER_WITH_STRIPE (TIME_05, 6 days ago) get
     * PLAYER_REGULAR's best time (TIME_36, 1750 s, 3 days ago)
     */
    private function tieThreePlayersForSecondPlace(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE puzzle_solving_time SET seconds_to_solve = 1750 WHERE id IN (:first, :second)',
            ['first' => PuzzleSolvingTimeFixture::TIME_04, 'second' => PuzzleSolvingTimeFixture::TIME_05],
        );
    }

    private function limitOf(TestLiveComponent $component): int
    {
        $puzzleTimes = $component->component();
        self::assertInstanceOf(PuzzleTimes::class, $puzzleTimes);

        return $puzzleTimes->limit;
    }

    /**
     * @return list<string>
     */
    private function rowKeys(Crawler $crawler): array
    {
        return $crawler->filter('tr[id^="leaderboard-row-"]')->each(
            static fn (Crawler $row): string => substr((string) $row->attr('id'), strlen('leaderboard-row-')),
        );
    }

    /**
     * @return list<string>
     */
    private function ranks(Crawler $crawler): array
    {
        return $crawler->filter('tr[id^="leaderboard-row-"] td.rank')->each(
            static fn (Crawler $cell): string => trim($cell->text()),
        );
    }

    private function buttonText(Crawler $crawler, string $action): string
    {
        return trim($crawler->filter(sprintf('button[data-live-action-param="%s"]', $action))->text());
    }
}
