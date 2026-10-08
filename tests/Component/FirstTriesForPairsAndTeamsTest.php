<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Component\PlayerSolvedPuzzles;
use SpeedPuzzling\Web\Component\PuzzleTimes;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * "Only first tries" and "Only unboxed" narrow pair and team results too - on a profile and on the puzzle page - and
 * stay on across the Solo / Pair / Team tabs. A pair's first try is everybody's (docs/features/first-try-integrity.md),
 * so the result's own flag is what the filter reads; the matching attempt leads its row, as on a solo row.
 *
 * PLAYER_WITH_STRIPE (member) has solo results and no pair/team result in the fixtures.
 */
final class FirstTriesForPairsAndTeamsTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testProfilePairRowsKeepOnlyFirstTriesLedByTheFirstTryAndTheSwitchStaysOnAcrossTabs(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        // With Grandma: a first try of PUZZLE_500_04 and a faster retry later, PUZZLE_500_05 only as a retry
        $firstTry = $this->addGroupTime('auth0|stripe005', ['Grandma'], PuzzleFixture::PUZZLE_500_04, 3600, daysAgo: 10, firstAttempt: true);
        $retry = $this->addGroupTime('auth0|stripe005', ['Grandma'], PuzzleFixture::PUZZLE_500_04, 2400, daysAgo: 2);
        $retryOnly = $this->addGroupTime('auth0|stripe005', ['Grandma'], PuzzleFixture::PUZZLE_500_05, 1800, daysAgo: 1);

        $component = $this->mountProfile($client, PlayerFixture::PLAYER_WITH_STRIPE);
        $component->set('onlyFirstTries', true);

        $component->call('changeResultsCategory', ['category' => 'duo']);
        $crawler = $component->render()->crawler();

        self::assertTrue(self::profile($component)->onlyFirstTries, 'The switch is not turned off by leaving the Solo tab');
        // The first try leads the row although the retry is faster - its time and its result detail
        self::assertSame([$firstTry], self::profileRowTimeIds($crawler));
        self::assertStringContainsString('01:00:00', $crawler->filter('.ps-time-value')->text());
        self::assertStringContainsString('Pair (1)', $crawler->filter('.puzzle-category-types')->text());

        $component->set('onlyFirstTries', false);

        self::assertSame([$retryOnly, $retry], self::profileRowTimeIds($component->render()->crawler()));
    }

    public function testProfileBothSwitchesNeedOneAttemptThatIsBothOnPairsAndTeams(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        // Pair: a boxed first try and an unboxed retry - first try and unboxed, but never both at once
        $this->addGroupTime('auth0|stripe005', ['Grandma'], PuzzleFixture::PUZZLE_1000_05, 7200, daysAgo: 5, firstAttempt: true);
        $unboxedRetry = $this->addGroupTime('auth0|stripe005', ['Grandma'], PuzzleFixture::PUZZLE_1000_05, 6000, daysAgo: 1, unboxed: true);
        // Team: one attempt that is both
        $unboxedFirstTry = $this->addGroupTime('auth0|stripe005', ['Grandma', 'Grandpa'], PuzzleFixture::PUZZLE_300, 1500, daysAgo: 3, firstAttempt: true, unboxed: true);

        $component = $this->mountProfile($client, PlayerFixture::PLAYER_WITH_STRIPE, 'duo');

        $component->set('onlyUnboxed', true);
        self::assertSame([$unboxedRetry], self::profileRowTimeIds($component->render()->crawler()));

        $component->set('onlyFirstTries', true);
        $crawler = $component->render()->crawler();
        self::assertSame([], self::profileRowTimeIds($crawler));
        self::assertStringContainsString('Team (1)', $crawler->filter('.puzzle-category-types')->text());

        $component->call('changeResultsCategory', ['category' => 'group']);
        self::assertSame([$unboxedFirstTry], self::profileRowTimeIds($component->render()->crawler()));
    }

    public function testFirstTriesAreMembersOnlyOnEveryTab(): void
    {
        // PLAYER_WITH_FAVORITES has no membership: the switch is disabled for them and a sent value is dropped
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->addGroupTime('auth0|stripe005', ['Grandma'], PuzzleFixture::PUZZLE_500_04, 3600, daysAgo: 10, firstAttempt: true);
        $retryOnly = $this->addGroupTime('auth0|stripe005', ['Grandma'], PuzzleFixture::PUZZLE_500_05, 1800, daysAgo: 1);

        $component = $this->mountProfile($client, PlayerFixture::PLAYER_WITH_STRIPE, 'duo');
        $component->set('onlyFirstTries', true);

        self::assertCount(2, self::profileRowTimeIds($component->render()->crawler()));
        self::assertContains($retryOnly, self::profileRowTimeIds($component->render()->crawler()));
        self::assertFalse(self::profile($component)->onlyFirstTries);
    }

    public function testPairFilterNarrowsPairsAndTeamsButNeverHidesSoloResults(): void
    {
        // A pair/team page links its members' profiles narrowed to that pair (?team=…&category=duo)
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_FAVORITES);
        $withGrandma = $this->addGroupTime('auth0|stripe005', ['Grandma'], PuzzleFixture::PUZZLE_500_04, 3600, daysAgo: 10);
        $this->addGroupTime('auth0|stripe005', ['Grandpa'], PuzzleFixture::PUZZLE_500_05, 1800, daysAgo: 1);
        $teamId = $this->teamOf($withGrandma);

        $unfiltered = $this->mountProfile($client, PlayerFixture::PLAYER_WITH_STRIPE)->render()->crawler();
        $soloRows = self::profileRowTimeIds($unfiltered);
        self::assertNotEmpty($soloRows);

        $component = $this->mountProfile($client, PlayerFixture::PLAYER_WITH_STRIPE, 'duo', $teamId);
        $crawler = $component->render()->crawler();

        self::assertSame([$withGrandma], self::profileRowTimeIds($crawler));
        $soloTab = $crawler->filter('[data-live-category-param="solo"]');
        self::assertSame(trim($unfiltered->filter('[data-live-category-param="solo"]')->text()), trim($soloTab->text()));
        self::assertNull($soloTab->attr('disabled'));

        $component->call('changeResultsCategory', ['category' => 'solo']);
        self::assertSame($soloRows, self::profileRowTimeIds($component->render()->crawler()));

        // "Reset" clears the pair/team filter too - it is counted among the active filters
        $component->call('changeResultsCategory', ['category' => 'duo']);
        $component->call('resetFilters');
        self::assertNull(self::profile($component)->team);
        self::assertCount(2, self::profileRowTimeIds($component->render()->crawler()));
    }

    public function testPuzzlePagePairsLeaderboardKeepsOnlyFirstTriesLedByTheFirstTry(): void
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_WITH_STRIPE);

        // Sarah + Grandma: a first try and a faster retry; Michael + Grandpa: only a retry
        $firstTry = $this->addGroupTime('auth0|stripe005', ['Grandma'], PuzzleFixture::PUZZLE_500_04, 3600, daysAgo: 10, firstAttempt: true);
        $retry = $this->addGroupTime('auth0|stripe005', ['Grandma'], PuzzleFixture::PUZZLE_500_04, 2400, daysAgo: 2);
        $otherPair = $this->addGroupTime('auth0|fav004', ['Grandpa'], PuzzleFixture::PUZZLE_500_04, 3000, daysAgo: 1);

        $component = $this->createLiveComponent('PuzzleTimes', [
            'puzzleId' => PuzzleFixture::PUZZLE_500_04,
            'piecesCount' => 500,
            'category' => 'duo',
        ], $client);
        $component->setRouteLocale('en');

        $crawler = $component->render()->crawler();
        self::assertSame([$retry, $otherPair], self::leaderboardRowTimeIds($crawler));
        self::assertCount(1, $crawler->filter('#only-first-attempt'), 'The switches are offered on the Pair tab');
        self::assertCount(1, $crawler->filter('#only-unboxed'));

        $component->set('onlyFirstTries', true);
        $crawler = $component->render()->crawler();

        self::assertSame([$firstTry], self::leaderboardRowTimeIds($crawler));
        self::assertStringContainsString('Pair (1)', $crawler->filter('.puzzle-category-types')->text());

        // Back on Solo the filter is still on
        $component->call('changeResultsCategory', ['category' => 'solo']);
        $leaderboard = $component->component();
        self::assertInstanceOf(PuzzleTimes::class, $leaderboard);
        self::assertTrue($leaderboard->onlyFirstTries);
    }

    private static function profile(TestLiveComponent $component): PlayerSolvedPuzzles
    {
        $profile = $component->component();
        self::assertInstanceOf(PlayerSolvedPuzzles::class, $profile);

        return $profile;
    }

    private function mountProfile(KernelBrowser $client, string $playerId, string $category = 'solo', null|string $team = null): TestLiveComponent
    {
        $component = $this->createLiveComponent('PlayerSolvedPuzzles', [
            'playerId' => $playerId,
            'category' => $category,
            'team' => $team,
        ], $client);
        $component->setRouteLocale('en');

        return $component;
    }

    /**
     * Added through the bus like the add form (pair/team assembled around the tracker), then dated and flagged by SQL
     *
     * @param array<string> $groupPlayers
     */
    private function addGroupTime(
        string $userId,
        array $groupPlayers,
        string $puzzleId,
        int $seconds,
        int $daysAgo,
        bool $firstAttempt = false,
        bool $unboxed = false,
    ): string {
        $timeId = Uuid::uuid7();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: null,
            // A distinct time each: the handler takes an identical copy within seconds for a resent form
            time: gmdate('H:i:s', $seconds),
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));

        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE puzzle_solving_time SET seconds_to_solve = :seconds, first_attempt = :firstAttempt, unboxed = :unboxed, finished_at = now() - make_interval(days => :daysAgo) WHERE id = :id",
            ['seconds' => $seconds, 'firstAttempt' => $firstAttempt ? 'true' : 'false', 'unboxed' => $unboxed ? 'true' : 'false', 'daysAgo' => $daysAgo, 'id' => $timeId->toString()],
        );

        return $timeId->toString();
    }

    private function teamOf(string $timeId): string
    {
        $teamId = self::getContainer()->get(Connection::class)->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertIsString($teamId);

        return $teamId;
    }

    /**
     * @return list<string>
     */
    private static function profileRowTimeIds(Crawler $crawler): array
    {
        return self::timeIdsOfLinks($crawler->filter('a.ps-time-value'));
    }

    /**
     * @return list<string>
     */
    private static function leaderboardRowTimeIds(Crawler $crawler): array
    {
        return self::timeIdsOfLinks($crawler->filter('a.lb-detail-link'));
    }

    /**
     * @return list<string>
     */
    private static function timeIdsOfLinks(Crawler $links): array
    {
        return $links->each(static fn (Crawler $link): string => basename((string) $link->attr('href')));
    }
}
