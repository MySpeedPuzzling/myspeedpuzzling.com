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
use Symfony\Contracts\Translation\TranslatorInterface;
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
        self::assertSame(['1', '2'], $this->ranks($crawler));
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
        self::assertSame('200', $this->ranks($crawler)[199]);
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

    public function testOwnRowFarBelowTheTopRowsComesWithItsNeighbours(): void
    {
        // 250 solvers at 5010, 5020 ... 7500 s; the viewer's 6505 s is 151st - two rows either side join it
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);
        $solvers = $this->seedSoloSolvers(PuzzleFixture::PUZZLE_1000_04, 250, secondsBetween: 10);
        $this->seedSoloTime(PuzzleFixture::PUZZLE_1000_04, PlayerFixture::PLAYER_WITH_STRIPE, 6505);

        $crawler = $this->mountSoloLeaderboard($client, PuzzleFixture::PUZZLE_1000_04, 1000)->render()->crawler();

        self::assertSame(
            [...array_slice($solvers, 0, PuzzleTimes::DEFAULT_LIMIT), $solvers[148], $solvers[149], PlayerFixture::PLAYER_WITH_STRIPE, $solvers[150], $solvers[151]],
            $this->rowKeys($crawler),
        );
        self::assertSame(['149', '150', '151', '152', '153'], array_slice($this->ranks($crawler), -5));

        // One "⋯" row counting rows 101-148, right above the neighbourhood; the viewer's row stays the "Jump to me" target
        $gap = $crawler->filter('tr.leaderboard-gap');
        self::assertCount(1, $gap);
        self::assertSame('⋯ 48 more', trim($gap->text()));
        self::assertSame('leaderboard-row-' . $solvers[148], $gap->nextAll()->first()->attr('id'));
        self::assertStringContainsString(
            'table-active-player',
            (string) $crawler->filter('#leaderboard-row-' . PlayerFixture::PLAYER_WITH_STRIPE)->attr('class'),
        );
        self::assertSame(
            '#leaderboard-row-' . PlayerFixture::PLAYER_WITH_STRIPE,
            $crawler->filter('a[href^="#leaderboard-row-"]')->attr('href'),
        );

        // 100 of the 250 others are slower; 6505 s - 6000 s (the 100th) = 505 s
        self::assertSame('#151 of 251', $crawler->filter('[data-testid="my-rank"]')->text());
        self::assertSame(['faster than 40% of puzzlers', '00:08:25 from the top 100'], $this->standing($crawler));

        // "Show more" still continues right after the top rows
        self::assertSame('Show 100 more', $this->buttonText($crawler, 'showMore'));
    }

    public function testGapRowCountsTheHiddenRowsAndShowsMoreWhenTapped(): void
    {
        // The viewer's 7405 s is 241st: the neighbourhood is 239-243, so rows 101-238 are hidden at first
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->seedSoloSolvers(PuzzleFixture::PUZZLE_1000_04, 250, secondsBetween: 10);
        $this->seedSoloTime(PuzzleFixture::PUZZLE_1000_04, PlayerFixture::PLAYER_WITH_STRIPE, 7405);
        $component = $this->mountSoloLeaderboard($client, PuzzleFixture::PUZZLE_1000_04, 1000);

        $gap = $component->render()->crawler()->filter('tr.leaderboard-gap');
        self::assertSame('⋯ 138 more', trim($gap->text()));
        // Tappable: the same action as the "Show 100 more" button
        self::assertCount(1, $gap->filter('button[data-action="live#action"][data-live-action-param="showMore"]'));

        $gap = $component->call('showMore')->render()->crawler()->filter('tr.leaderboard-gap');
        self::assertSame('⋯ 38 more', trim($gap->text()));

        // Rows 1-300 reach the neighbourhood: nothing is hidden above it any more
        self::assertCount(0, $component->call('showMore')->render()->crawler()->filter('tr.leaderboard-gap'));
    }

    public function testGapRowCountReadsNaturallyInCzech(): void
    {
        self::bootKernel();
        $translator = self::getContainer()->get(TranslatorInterface::class);

        self::assertSame('další 1', $translator->trans('puzzle_times.leaderboard.hidden_rows', ['%count%' => 1], 'messages', 'cs'));
        self::assertSame('další 3', $translator->trans('puzzle_times.leaderboard.hidden_rows', ['%count%' => 3], 'messages', 'cs'));
        self::assertSame('dalších 497', $translator->trans('puzzle_times.leaderboard.hidden_rows', ['%count%' => 497], 'messages', 'cs'));
    }

    public function testNeighboursRightBelowTheTopRowsJoinThemWithoutAGap(): void
    {
        // The viewer's 6015 s is 102nd: rows 100 to 104 are shown, straight after the top 100
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);
        $solvers = $this->seedSoloSolvers(PuzzleFixture::PUZZLE_1000_04, 250, secondsBetween: 10);
        $this->seedSoloTime(PuzzleFixture::PUZZLE_1000_04, PlayerFixture::PLAYER_WITH_STRIPE, 6015);

        $crawler = $this->mountSoloLeaderboard($client, PuzzleFixture::PUZZLE_1000_04, 1000)->render()->crawler();

        self::assertSame(
            [...array_slice($solvers, 0, 101), PlayerFixture::PLAYER_WITH_STRIPE, $solvers[101], $solvers[102]],
            $this->rowKeys($crawler),
        );
        self::assertCount(0, $crawler->filter('tr.leaderboard-gap'));
    }

    public function testOwnRowAmongTheTopRowsStillShowsTheRowsBelowIt(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->mountSoloLeaderboard($client, PuzzleFixture::PUZZLE_500_01, 500, limit: 2)->render()->crawler();

        self::assertSame(
            [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_WITH_FAVORITES],
            $this->rowKeys($crawler),
        );
        self::assertCount(0, $crawler->filter('tr.leaderboard-gap'));
    }

    public function testPositionLineOfTheFastestIsJustTheRank(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->mountSoloLeaderboard($client, PuzzleFixture::PUZZLE_500_01, 500)->render()->crawler();

        self::assertSame('#1 of 4', $crawler->filter('[data-testid="my-rank"]')->text());
        self::assertSame([], $this->standing($crawler));
    }

    public function testPositionLineOfASmallLeaderboardHasNoPercentage(): void
    {
        // 3rd of 4 - a share of three other people says little, the gap to the fastest does
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->mountSoloLeaderboard($client, PuzzleFixture::PUZZLE_500_01, 500)->render()->crawler();

        self::assertSame('#3 of 4', $crawler->filter('[data-testid="my-rank"]')->text());
        self::assertSame(['00:15:00 behind the fastest'], $this->standing($crawler));
    }

    public function testRowsShowTheirGapsToTheFastestAndToTheNextFasterTime(): void
    {
        $client = self::createClient();

        $crawler = $this->mountSoloLeaderboard($client, PuzzleFixture::PUZZLE_500_01, 500)->render()->crawler();

        self::assertCount(0, $crawler->filter('#leaderboard-row-' . PlayerFixture::PLAYER_ADMIN . ' .lb-gap'));
        // 2nd place: the time above is the fastest one - one gap, as "the time above" (↑, right slot), no ①
        self::assertCount(1, $this->gaps($crawler, PlayerFixture::PLAYER_REGULAR));
        self::assertCount(1, $crawler->filter('#leaderboard-row-' . PlayerFixture::PLAYER_REGULAR . ' .lb-gaps > .lb-gap:last-child .bi-arrow-up'));
        self::assertCount(0, $crawler->filter('#leaderboard-row-' . PlayerFixture::PLAYER_REGULAR . ' .bi-1-circle'));
        // 3rd place: behind the fastest, then behind the 2nd place (00:34:10)
        self::assertSame(['+15:00', '+05:50'], $this->gaps($crawler, PlayerFixture::PLAYER_WITH_STRIPE));
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
        self::assertSame(['1', '2', '2', '2'], $this->ranks($crawler));
    }

    public function testNeighbourhoodKeepsTiedRanks(): void
    {
        // The viewer's 6500 s ties with the 150th solver and was finished earlier, so it comes first
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);
        $solvers = $this->seedSoloSolvers(PuzzleFixture::PUZZLE_1000_04, 250, secondsBetween: 10);
        $this->seedSoloTime(PuzzleFixture::PUZZLE_1000_04, PlayerFixture::PLAYER_WITH_STRIPE, 6500);

        $crawler = $this->mountSoloLeaderboard($client, PuzzleFixture::PUZZLE_1000_04, 1000)->render()->crawler();

        self::assertSame(
            [$solvers[147], $solvers[148], PlayerFixture::PLAYER_WITH_STRIPE, $solvers[149], $solvers[150]],
            array_slice($this->rowKeys($crawler), -5),
        );
        self::assertSame(['148', '149', '150', '150', '152'], array_slice($this->ranks($crawler), -5));
        self::assertSame('#150 of 251', $crawler->filter('[data-testid="my-rank"]')->text());
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
    private function gaps(Crawler $crawler, string $rowKey): array
    {
        // Two fixed slots per row - an empty one is not a gap
        return array_values(array_filter($crawler->filter('#leaderboard-row-' . $rowKey . ' .lb-gap')->each(
            static fn (Crawler $gap): string => trim($gap->text()),
        ), static fn (string $gap): bool => $gap !== ''));
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

    /**
     * The button under the table - the "⋯ N more" gap row runs "showMore" too
     */
    private function buttonText(Crawler $crawler, string $action): string
    {
        return trim($crawler->filter(sprintf('button[data-live-action-param="%s"]', $action))->last()->text());
    }

    /**
     * How the viewer stands, after their own time - the parts of the line under the numbers
     *
     * @return list<string>
     */
    private function standing(Crawler $crawler): array
    {
        return array_slice($crawler->filter('[data-testid="my-position"] > span')->each(
            static fn (Crawler $part): string => trim($part->text()),
        ), 1);
    }
}
