<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Component\PuzzleTimes;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\LeaderboardSeeding;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The switch between the distribution and the ranking above a puzzle leaderboard, remembered per player
 * (docs/features/puzzle-leaderboard-chart.md). PLAYER_WITH_STRIPE is a member, PLAYER_REGULAR is not.
 */
final class PuzzleTimesChartViewTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use LeaderboardSeeding;
    use QueryCountAssertions;

    public function testMembersStartOnTheDistributionWithItsButtonPressed(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->seedSoloSolvers(PuzzleFixture::PUZZLE_1000_04, 80);

        $crawler = $this->mount($client)->render()->crawler();

        self::assertSame(['true', 'false'], self::pressed($crawler));
        self::assertCount(1, $crawler->filter('#leaderboard-chart-distribution [data-testid="leaderboard-distribution"]'));
        self::assertCount(0, $crawler->filter('#leaderboard-chart-ranking'));

        // Icons only - the words are the buttons' accessible names and tooltips
        $ranking = $crawler->filter('[data-testid="leaderboard-chart-switch-ranking"]');
        self::assertSame('Rankings', $ranking->attr('aria-label'));
        self::assertSame('Rankings', $ranking->attr('title'));
        self::assertSame('', trim($ranking->text()));
        self::assertCount(1, $ranking->filter('svg'));
        self::assertSame('Chart view', $crawler->filter('[data-testid="leaderboard-chart-switch"]')->attr('aria-label'));
    }

    public function testSwitchingToTheRankingShowsABarPerRowAndRemembersIt(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->seedSoloSolvers(PuzzleFixture::PUZZLE_1000_04, 80);

        $component = $this->mount($client);
        $crawler = $component->call('changeChartView', ['view' => 'ranking'])->render()->crawler();

        // A container with another id: the Live morph rebuilds the chart instead of morphing one into the other
        self::assertCount(1, $crawler->filter('#leaderboard-chart-ranking [data-testid="leaderboard-individual"]'));
        self::assertCount(0, $crawler->filter('#leaderboard-chart-distribution'));
        self::assertSame(['false', 'true'], self::pressed($crawler));
        self::assertSame('ranking', $this->storedView(PlayerFixture::PLAYER_WITH_STRIPE));

        // Every puzzle page opens on it from now on
        $client->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_04);
        self::assertSelectorExists('[data-testid="leaderboard-individual"]');
        self::assertSelectorExists('[data-testid="leaderboard-chart-switch-ranking"][aria-pressed="true"]');

        // And back
        $crawler = $this->mount($client)->call('changeChartView', ['view' => 'distribution'])->render()->crawler();
        self::assertCount(1, $crawler->filter('[data-testid="leaderboard-distribution"]'));
        self::assertSame('distribution', $this->storedView(PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testSwitchingLeavesTheTableAsItIs(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->seedSoloSolvers(PuzzleFixture::PUZZLE_1000_04, 250);

        $component = $this->mount($client);
        $component->call('showMore');
        $component->call('changeChartView', ['view' => 'ranking']);

        $puzzleTimes = $component->component();
        self::assertInstanceOf(PuzzleTimes::class, $puzzleTimes);
        self::assertSame(200, $puzzleTimes->limit);
    }

    public function testShortLeaderboardsAreAlwaysTheRankingWithoutASwitch(): void
    {
        // PUZZLE_500_01: four solvers this viewer can see - fewer than PuzzleTimesChart::SWITCH_MIN_ROWS
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        $component = $this->createLiveComponent('PuzzleTimes', [
            'puzzleId' => PuzzleFixture::PUZZLE_500_01,
            'piecesCount' => 500,
        ], $client);
        $component->setRouteLocale('en');
        $crawler = $component->render()->crawler();

        self::assertCount(0, $crawler->filter('[data-testid="leaderboard-chart-switch"]'));
        self::assertCount(1, $crawler->filter('#leaderboard-chart-ranking [data-testid="leaderboard-individual"]'));

        // The stored choice is untouched - longer leaderboards still open on it
        self::assertSame('distribution', $this->storedView(PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testAnUnknownViewChangesNothing(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->seedSoloSolvers(PuzzleFixture::PUZZLE_1000_04, 60);

        $crawler = $this->mount($client)->call('changeChartView', ['view' => 'pie'])->render()->crawler();

        self::assertCount(1, $crawler->filter('[data-testid="leaderboard-distribution"]'));
        self::assertSame('distribution', $this->storedView(PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testNonMembersGetNoSwitch(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_REGULAR);
        $this->seedSoloSolvers(PuzzleFixture::PUZZLE_1000_04, 60);

        $crawler = $this->mount($client)->render()->crawler();

        self::assertCount(0, $crawler->filter('[data-testid="leaderboard-chart-switch"]'));
        self::assertCount(1, $crawler->filter('.puzzle-detail-chart-placeholder'));
    }

    public function testWithoutASignedInPlayerTheActionStoresNothing(): void
    {
        $client = self::createClient();
        $this->seedSoloSolvers(PuzzleFixture::PUZZLE_1000_04, 60);

        $crawler = $this->mount($client)->call('changeChartView', ['view' => 'ranking'])->render()->crawler();

        self::assertCount(0, $crawler->filter('[data-testid="leaderboard-chart-switch"]'));
        $stored = self::getContainer()->get(Connection::class)
            ->fetchOne("SELECT COUNT(*) FROM player WHERE leaderboard_chart_view <> 'distribution'");
        self::assertSame(0, $stored);
    }

    /**
     * The choice rides on the signed-in player's profile row: no query of its own, the ranking page costs the
     * distribution page's queries
     */
    public function testTheStoredChoiceCostsNoQuery(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->seedSoloSolvers(PuzzleFixture::PUZZLE_1000_04, 60);

        // The first page view of the day also does one-off work (sign-in bookkeeping, activity) - not what is compared
        $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_04);

        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_04);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="leaderboard-distribution"]');
        $distributionQueries = $this->queryCount($browser);
        $mentions = array_filter($this->executedSql($browser), static fn (string $sql): bool => str_contains($sql, 'leaderboard_chart_view'));
        self::assertCount(1, $mentions, 'Only the profile query reads the choice');

        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE player SET leaderboard_chart_view = 'ranking' WHERE id = :id",
            ['id' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_04);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="leaderboard-individual"]');
        self::assertSame($distributionQueries, $this->queryCount($browser));
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
     * aria-pressed of the Distribution and the Rankings button
     *
     * @return list<null|string>
     */
    private static function pressed(Crawler $crawler): array
    {
        return [
            $crawler->filter('[data-testid="leaderboard-chart-switch-distribution"]')->attr('aria-pressed'),
            $crawler->filter('[data-testid="leaderboard-chart-switch-ranking"]')->attr('aria-pressed'),
        ];
    }

    private function storedView(string $playerId): mixed
    {
        return self::getContainer()->get(Connection::class)
            ->fetchOne('SELECT leaderboard_chart_view FROM player WHERE id = :id', ['id' => $playerId]);
    }
}
