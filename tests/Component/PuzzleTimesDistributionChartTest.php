<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\LeaderboardSeeding;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The members' leaderboard chart as the puzzle page renders it (docs/features/puzzle-leaderboard-chart.md).
 * PLAYER_WITH_STRIPE is a member, PLAYER_REGULAR is not.
 */
final class PuzzleTimesDistributionChartTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use LeaderboardSeeding;

    private const string CHART_VALUE = 'data-symfony--ux-chartjs--chart-view-value';

    public function testMembersSeeTheDistributionOfALongLeaderboard(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->seedSoloSolvers(PuzzleFixture::PUZZLE_1000_04, 60);

        $crawler = $this->mount($client)->render()->crawler();

        $canvas = $crawler->filter('[data-testid="leaderboard-distribution"] canvas[role="img"]');
        self::assertCount(1, $canvas);
        self::assertStringStartsWith('Times of 60 puzzlers, median ', (string) $canvas->attr('aria-label'));
        self::assertCount(0, $crawler->filter('[data-testid="leaderboard-individual"]'));

        $chart = self::chartData($crawler);
        self::assertSame(60, array_sum($chart['counts']));
        self::assertLessThanOrEqual(32, count($chart['labels']));
    }

    /**
     * 80 solvers finishing in 5001 ... 5080 s; each case marks the fastest N of them for one filter.
     *
     * @return iterable<string, array{string, bool|string, int}>
     */
    public static function provideFilters(): iterable
    {
        yield 'no filter' => ['', true, 80];
        yield 'first attempts only, 60 left' => ['onlyFirstTries', true, 60];
        yield 'unboxed only, 40 left' => ['onlyUnboxed', true, 40];
        yield 'one country, 70 left' => ['country', 'cz', 70];
        yield 'favorite players, 20 left' => ['onlyFavoritePlayers', true, 20];
    }

    /**
     * The chart is drawn from the filtered rows - and stays the distribution however few are left (the member switches
     * to the ranking themselves, see PuzzleTimesChartViewTest)
     */
    #[DataProvider('provideFilters')]
    public function testEveryFilterFeedsTheChart(string $filter, bool|string $value, int $rows): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);
        $solvers = $this->seedSoloSolvers(PuzzleFixture::PUZZLE_1000_04, 80);
        $database = self::getContainer()->get(Connection::class);
        $fastest = ['puzzleId' => PuzzleFixture::PUZZLE_1000_04, 'seconds' => 5000 + $rows];
        $types = ['seconds' => ParameterType::INTEGER];

        match ($filter) {
            'onlyFirstTries' => $database->executeStatement('UPDATE puzzle_solving_time SET first_attempt = true WHERE puzzle_id = :puzzleId AND seconds_to_solve <= :seconds', $fastest, $types),
            'onlyUnboxed' => $database->executeStatement('UPDATE puzzle_solving_time SET unboxed = true WHERE puzzle_id = :puzzleId AND seconds_to_solve <= :seconds', $fastest, $types),
            'country' => $database->executeStatement("UPDATE player SET country = 'cz' WHERE id IN (SELECT player_id FROM puzzle_solving_time WHERE puzzle_id = :puzzleId AND seconds_to_solve <= :seconds)", $fastest, $types),
            'onlyFavoritePlayers' => $database->executeStatement('UPDATE player SET favorite_players = :favorites WHERE id = :viewer', [
                'favorites' => json_encode(array_slice($solvers, 0, $rows), JSON_THROW_ON_ERROR),
                'viewer' => PlayerFixture::PLAYER_WITH_STRIPE,
            ]),
            default => 0,
        };

        $component = $this->mount($client);

        // Before the filter the bars hold 60 first tries and 20 repeats, and the legend tells the two colours apart
        if ($filter === 'onlyFirstTries') {
            self::assertCount(1, $component->render()->crawler()->filter('[data-testid="leaderboard-legend"]'));
        }

        if ($filter !== '') {
            $component->set($filter, $value);
        }

        $crawler = $component->render()->crawler();
        self::assertCount(1, $crawler->filter('[data-testid="leaderboard-distribution"]'));

        $data = self::chartData($crawler);
        self::assertSame($rows, array_sum($data['counts']));

        // First tries only: every bar is a first try - a single colour, so no legend
        if ($filter === 'onlyFirstTries') {
            self::assertSame(0, array_sum($data['repeats']));
            self::assertCount(0, $crawler->filter('[data-testid="leaderboard-legend"]'));
        }
    }

    public function testMyPairsOnlyFeedsThePairChart(): void
    {
        // PUZZLE_1000_01 has one fixture pair; the viewer adds two of their own
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->addPairTime('auth0|stripe005', ['#admin'], PuzzleFixture::PUZZLE_1000_01);
        $this->addPairTime('auth0|stripe005', ['Grandma'], PuzzleFixture::PUZZLE_1000_01);

        $component = $this->createLiveComponent('PuzzleTimes', [
            'puzzleId' => PuzzleFixture::PUZZLE_1000_01,
            'piecesCount' => 1000,
            'category' => 'duo',
        ], $client);
        $component->setRouteLocale('en');

        self::assertSame(3, array_sum(self::chartData($component->render()->crawler())['counts']));
        self::assertSame(2, array_sum(self::chartData($component->set('onlyMyTeams', true)->render()->crawler())['counts']));
    }

    public function testBarPerRowChartLeavesOutHiddenPlayersAndCarriesTheMarkersAndSummary(): void
    {
        // PUZZLE_500_01 has five solvers, but PLAYER_PRIVATE's profile is hidden from this viewer
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        $component = $this->createLiveComponent('PuzzleTimes', [
            'puzzleId' => PuzzleFixture::PUZZLE_500_01,
            'piecesCount' => 500,
        ], $client);
        $component->setRouteLocale('en');
        $crawler = $component->call('changeChartView', ['view' => 'ranking'])->render()->crawler();

        $wrapper = $crawler->filter('[data-testid="leaderboard-individual"]');
        self::assertSame('time-chart leaderboard-chart', $wrapper->attr('data-controller'));
        self::assertSame(
            'Times of 4 puzzlers, median 00:32:05. Your time: 00:35:00.',
            $wrapper->filter('canvas[role="img"]')->attr('aria-label'),
        );
        self::assertCount(4, self::chartData($crawler)['labels']);
    }

    public function testNonMembersGetTheLockedPlaceholderWithoutAnyChartData(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_REGULAR);
        $this->seedSoloSolvers(PuzzleFixture::PUZZLE_1000_04, 60);

        $crawler = $this->mount($client)->render()->crawler();

        self::assertCount(1, $crawler->filter('.puzzle-detail-chart-placeholder'));
        self::assertCount(0, $crawler->filter('canvas'));
        self::assertCount(0, $crawler->filter('[' . self::CHART_VALUE . ']'));
    }

    /**
     * @param array<string> $groupPlayers
     */
    private function addPairTime(string $userId, array $groupPlayers, string $puzzleId): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleSolvingTime(
            timeId: Uuid::uuid7(),
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: null,
            time: '05:00:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));
    }

    private function mount(KernelBrowser $client): TestLiveComponent
    {
        $component = $this->createLiveComponent('PuzzleTimes', [
            'puzzleId' => PuzzleFixture::PUZZLE_1000_04,
            'piecesCount' => 1000,
        ], $client);
        $component->setRouteLocale('en');

        return $component;
    }

    /**
     * Per bar: every dataset added up (the distribution stacks first tries on repeats), and the repeats alone
     *
     * @return array{labels: array<mixed>, counts: array<int>, repeats: array<int>}
     */
    private static function chartData(Crawler $crawler): array
    {
        // The leaderboard's chart - the viewer's own "All my times" chart can come first
        $view = $crawler
            ->filter('[data-testid^="leaderboard-"] canvas[' . self::CHART_VALUE . ']')
            ->attr(self::CHART_VALUE);
        self::assertNotNull($view);

        $chart = json_decode($view, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($chart);
        self::assertIsArray($chart['data']);
        self::assertIsArray($chart['data']['labels']);
        self::assertIsArray($chart['data']['datasets']);

        $counts = [];
        $repeats = [];

        foreach ($chart['data']['datasets'] as $index => $dataset) {
            self::assertIsArray($dataset);
            self::assertIsArray($dataset['data']);

            foreach ($dataset['data'] as $bar => $value) {
                self::assertIsInt($value);
                $counts[$bar] = ($counts[$bar] ?? 0) + $value;

                if ($index === 1) {
                    $repeats[$bar] = $value;
                }
            }
        }

        return ['labels' => $chart['data']['labels'], 'counts' => $counts, 'repeats' => $repeats];
    }
}
