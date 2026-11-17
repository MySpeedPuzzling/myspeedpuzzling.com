<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\AnalyticsStudio;

use SpeedPuzzling\Web\Tests\AnalyticsStudioTesting;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The analytics studio's state JSON (docs/features/competitions-management/analytics-studio.md §3.1, §6) on the
 * "Results Cup": Group A published (Anna 1st, Ben and Cara tied 2nd, Dan unfinished, Eva did not start, Filip no
 * result), Group B not published (Gina - a private player -, Hugo - PLAYER_REGULAR -, Ivan), Pairs not published.
 */
final class AnalyticsStudioStateControllerTest extends WebTestCase
{
    use AnalyticsStudioTesting;

    private const string UNKNOWN = '018d0020-0000-0000-0000-00000000ffff';

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testAPublishedSoloRoundShowsTheOfficialRanking(): void
    {
        $browser = self::createClient();
        $this->clearAnalyticsStudioCache();

        $state = $this->studioJson($browser, '/analytics-studio/' . F::ROUND_GROUP_A . '/state');
        $response = $browser->getResponse();
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame([], $response->headers->getCookies(), 'the stateless firewall never sets a cookie');

        self::assertSame(TestClock::milliseconds(), $state['serverNowMs']);
        self::assertIsArray($state['round']);
        self::assertSame('group-a', $state['round']['slug']);
        self::assertSame('solo', $state['round']['category']);
        self::assertTrue($state['round']['published']);
        self::assertArrayNotHasKey('puzzleFormat', $state['round']);
        self::assertSame(['/round-stopwatch/' . F::ROUND_GROUP_A], $state['mercure']['topics'] ?? null);

        self::assertIsArray($state['slots']);
        self::assertCount(1, $state['slots']);
        self::assertSame('A', $state['slots'][0]['label']);
        self::assertSame(1000, $state['slots'][0]['puzzle']['pieces']);

        $names = array_map(static fn (array $entrant): string => $entrant['members'][0]['name'], $state['entrants']);
        self::assertSame(['Anna Fast', 'Ben Steady', 'Cara Tied', 'Dan Unfinished', 'Eva Noshow', 'Filip Pending'], $names);

        // Anna is the admin's public profile: analysable, with the profile id and code
        $anna = $state['entrants'][0]['members'][0];
        self::assertSame(['name' => 'Anna Fast', 'country' => 'cz', 'profile' => '/en/player-profile/' . PlayerFixture::PLAYER_ADMIN, 'code' => 'ADMIN', 'analysable' => true], $anna);
        self::assertFalse($state['entrants'][1]['members'][0]['analysable'], 'Ben is linked to nobody');

        $results = array_column($state['results'], null, 'key');
        self::assertSame([F::ENTRY_A_ANNA, F::ENTRY_A_BEN, F::ENTRY_A_CARA, F::ENTRY_A_DAN], array_keys($results), 'the official rows in rank order');
        self::assertSame(['key' => F::ENTRY_A_ANNA, 'state' => 'official', 'seconds' => 3600, 'piecesPlaced' => null, 'piecesCount' => null, 'rank' => 1, 'qualified' => true], $results[F::ENTRY_A_ANNA]);
        self::assertSame(2, $results[F::ENTRY_A_CARA]['rank'], 'ties share the official place');
        self::assertSame(['seconds' => null, 'piecesPlaced' => 850, 'piecesCount' => 1000, 'rank' => 4], array_intersect_key($results[F::ENTRY_A_DAN], ['seconds' => 1, 'piecesPlaced' => 1, 'piecesCount' => 1, 'rank' => 1]));
        self::assertStringNotContainsString('didNotStart', (string) $browser->getResponse()->getContent());
    }

    public function testAnUnpublishedRoundShowsResultsOnlyWhileTheEventShowsConfirmedResults(): void
    {
        $browser = self::createClient();
        $database = $this->studioDatabase();
        $url = '/analytics-studio/' . F::ROUND_GROUP_B . '/state';

        $this->clearAnalyticsStudioCache();
        $state = $this->studioJson($browser, $url);
        self::assertFalse($state['round']['published']);
        self::assertFalse($state['round']['liveResults']);
        self::assertSame([], $state['results'], 'nothing before publishing by default');

        // The live results setting does nothing while the competitor pages are off (the competitor page rule)
        $database->executeStatement('UPDATE competition SET competitor_pages_live_results = true WHERE id = :id', ['id' => F::COMPETITION_RESULTS_CUP]);
        $this->clearAnalyticsStudioCache();
        self::assertSame([], $this->studioJson($browser, $url)['results']);

        $database->executeStatement('UPDATE competition SET competitor_pages = true WHERE id = :id', ['id' => F::COMPETITION_RESULTS_CUP]);
        $this->clearAnalyticsStudioCache();
        $state = $this->studioJson($browser, $url);
        self::assertTrue($state['round']['liveResults']);
        self::assertSame(['live'], array_values(array_unique(array_column($state['results'], 'state'))));
        self::assertSame([null], array_values(array_unique(array_column($state['results'], 'rank'))), 'never a place before publishing');
        self::assertSame([false], array_values(array_unique(array_column($state['results'], 'qualified'))));
        // The entrants' order (Gina, Hugo, Ivan) - not a leaderboard (R8)
        self::assertSame([F::ENTRY_B_GINA, F::ENTRY_B_HUGO, F::ENTRY_B_IVAN], array_column($state['results'], 'key'));
    }

    public function testAPrivatePlayerIsTheOrganisersNameOnly(): void
    {
        $browser = self::createClient();
        $this->clearAnalyticsStudioCache();

        $state = $this->studioJson($browser, '/analytics-studio/' . F::ROUND_GROUP_B . '/state');
        $members = array_merge(...array_map(static fn (array $entrant): array => $entrant['members'], $state['entrants']));
        $byName = array_column($members, null, 'name');

        self::assertSame(['name' => 'Gina Quick', 'country' => 'us', 'profile' => null, 'code' => null, 'analysable' => false], $byName['Gina Quick']);
        self::assertSame(['name' => 'Ivan Last', 'country' => 'sk', 'profile' => null, 'code' => null, 'analysable' => false], $byName['Ivan Last'], 'unlinked looks exactly like private');
        self::assertSame('/en/player-profile/' . PlayerFixture::PLAYER_REGULAR, $byName['Hugo Slow']['profile']);
        self::assertStringNotContainsString(PlayerFixture::PLAYER_PRIVATE, (string) $browser->getResponse()->getContent());
    }

    public function testAPairRoundListsItsPairsWithTheirMembers(): void
    {
        $browser = self::createClient();
        $database = $this->studioDatabase();
        $database->executeStatement('UPDATE competition SET competitor_pages = true, competitor_pages_live_results = true WHERE id = :id', ['id' => F::COMPETITION_RESULTS_CUP]);
        $this->clearAnalyticsStudioCache();

        $state = $this->studioJson($browser, '/analytics-studio/' . F::ROUND_PAIRS . '/state');
        self::assertSame('duo', $state['round']['category']);

        $entrants = array_column($state['entrants'], null, 'key');
        self::assertTrue($entrants[F::TEAM_SHARKS]['isTeam']);
        self::assertSame('Puzzle Sharks', $entrants[F::TEAM_SHARKS]['teamName']);
        self::assertSame(['Anna Fast', 'Ben Steady'], array_column($entrants[F::TEAM_SHARKS]['members'], 'name'));

        $results = array_column($state['results'], null, 'key');
        self::assertSame(5400, $results[F::TEAM_SHARKS]['seconds']);
        self::assertSame(['piecesPlaced' => 1700, 'piecesCount' => 2000], array_intersect_key($results[F::TEAM_CORNERS], ['piecesPlaced' => 1, 'piecesCount' => 1]));
        self::assertArrayNotHasKey(F::TEAM_UNNAMED, $results, 'no result yet');
    }

    public function testAPickRoundCarriesItsFormatAndTheChosenPuzzlesByLabel(): void
    {
        $browser = self::createClient();
        $database = $this->studioDatabase();
        $roundPuzzleId = $database->fetchOne('SELECT id FROM competition_round_puzzle WHERE round_id = :round', ['round' => F::ROUND_GROUP_B]);
        $database->executeStatement(
            "UPDATE competition_round SET puzzle_format = 'pick', puzzles_to_solve = 1, results_published_at = " . TestClock::SQL_NOW . ' WHERE id = :id',
            ['id' => F::ROUND_GROUP_B],
        );
        $database->executeStatement(
            'UPDATE competition_participant_round SET result_puzzles = :puzzles WHERE round_id = :round',
            ['puzzles' => json_encode([['roundPuzzleId' => $roundPuzzleId, 'done' => true]], JSON_THROW_ON_ERROR), 'round' => F::ROUND_GROUP_B],
        );
        $this->clearAnalyticsStudioCache();

        $state = $this->studioJson($browser, '/analytics-studio/' . F::ROUND_GROUP_B . '/state');
        self::assertSame(['kind' => 'pick', 'toSolve' => 1], $state['round']['puzzleFormat']);
        self::assertSame([['label' => 'A', 'done' => true, 'seconds' => null, 'piecesPlaced' => null]], $state['results'][0]['puzzles']);
    }

    public function testNothingOfAnEventThatIsNotPublic(): void
    {
        $browser = self::createClient();
        $database = $this->studioDatabase();
        $this->clearAnalyticsStudioCache();

        $this->studioJson($browser, '/analytics-studio/' . self::UNKNOWN . '/state', 404);
        $this->studioJson($browser, '/analytics-studio/not-a-uuid/state', 404);

        // A round without a slug
        $database->executeStatement('UPDATE competition_round SET slug = NULL WHERE id = :id', ['id' => F::ROUND_FINAL]);
        $this->studioJson($browser, '/analytics-studio/' . F::ROUND_FINAL . '/state', 404);

        // A draft - for its organiser too (the JSON has no viewer)
        $database->executeStatement('UPDATE competition SET is_draft = true WHERE id = :id', ['id' => F::COMPETITION_RESULTS_CUP]);
        foreach (['state', 'analytics', 'predictions', 'entrant/' . F::ENTRY_A_ANNA] as $answer) {
            $this->studioJson($browser, '/analytics-studio/' . F::ROUND_GROUP_A . '/' . $answer, 404);
        }

        // Unapproved
        $database->executeStatement('UPDATE competition SET is_draft = false, approved_at = NULL WHERE id = :id', ['id' => F::COMPETITION_RESULTS_CUP]);
        $this->studioJson($browser, '/analytics-studio/' . F::ROUND_GROUP_A . '/state', 404);
    }

    public function testTheStateIsKeptTwoSecondsButAStopwatchChangeIsANewAnswer(): void
    {
        $browser = self::createClient();
        $database = $this->studioDatabase();
        $url = '/analytics-studio/' . F::ROUND_GROUP_B . '/state';
        $this->clearAnalyticsStudioCache();

        $this->studioJson($browser, $url);
        $database->executeStatement("UPDATE competition_participant SET name = 'Renamed Hugo' WHERE id = :id", ['id' => F::PARTICIPANT_HUGO]);
        self::assertStringNotContainsString('Renamed Hugo', json_encode($this->studioJson($browser, $url), JSON_THROW_ON_ERROR), 'kept for 2 s');

        $database->executeStatement(
            "UPDATE competition_round SET stopwatch_status = 'running', stopwatch_started_at = " . TestClock::SQL_NOW . ' WHERE id = :id',
            ['id' => F::ROUND_GROUP_B],
        );
        $state = $this->studioJson($browser, $url);
        self::assertSame('running', $state['round']['stopwatch']['status']);
        self::assertStringContainsString('Renamed Hugo', json_encode($state, JSON_THROW_ON_ERROR), 'a stopwatch change is a new key');
    }
}
