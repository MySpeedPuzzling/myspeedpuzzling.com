<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\RegenerateCompetitionDisplayToken;
use SpeedPuzzling\Web\Query\GetRoundStopwatchState;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\CreatesCompetitionDisplays;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The read side of competition displays (D-displays-overlays.md §7.2, §9.4): `GET /display/{token}/state` and
 * `/design` by the token alone - which round, its stopwatch, and official results only as far as the design shows
 * them and the organiser allowed them (published, or `source: live`), with the organiser's names and opaque keys.
 *
 * OfficialResultsFixture's Results Cup (10 days ago): Group A published (Anna 1., Ben/Cara 2., Dan unfinished, Eva did
 * not start, Filip no result), Group B unpublished (Gina Quick = the private PLAYER_PRIVATE "Jane Smith", Hugo, Ivan),
 * Pairs unpublished (teams).
 */
final class CompetitionDisplayStateControllerTest extends WebTestCase
{
    use CreatesCompetitionDisplays;
    use QueryCountAssertions;

    private const array LEADERBOARD = ['type' => 'leaderboard', 'props' => ['rows' => 10, 'columns' => ['rank', 'flag', 'name', 'members', 'result', 'table']]];
    private const array LIVE_LEADERBOARD = ['type' => 'leaderboard', 'props' => ['rows' => 10, 'source' => 'live']];
    private const array FINISHED = ['type' => 'finished_count'];
    private const array TICKER = ['type' => 'ticker', 'props' => ['rows' => 2]];

    public function testAnUnknownOrMalformedTokenIs404WithoutASession(): void
    {
        $browser = self::createClient();

        foreach (['/display/' . str_repeat('A', 22) . '/state', '/display/' . str_repeat('A', 22) . '/design', '/display/short/state', '/display/0OIl0OIl0OIl0OIl0OIl0O/state'] as $url) {
            $browser->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }

    public function testTheStateIsNeverCachedAndStartsNoSession(): void
    {
        $browser = self::createClient();
        $token = self::createDisplay(OfficialResultsFixture::COMPETITION_RESULTS_CUP, pinnedRoundId: OfficialResultsFixture::ROUND_GROUP_A);

        // Even with a signed-in organiser's session cookie: the token decides, the stateless firewall never reads the
        // session, the answer is the same for everybody (the first request after loginUser() carries the test's token
        // in the container - the second one is what a browser gets)
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        self::displayState($browser, $token);
        [, $state] = self::displayState($browser, $token);

        $response = $browser->getResponse();
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame([], $response->headers->getCookies());
        self::assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));

        self::assertSame('Results Cup', self::at($state, 'competition.name'));
        self::assertSame(OfficialResultsFixture::ROUND_GROUP_A, self::at($state, 'round.id'));
        self::assertSame('Group A', self::at($state, 'round.name'));
        self::assertSame([OfficialResultsFixture::ROUND_GROUP_A], $state['followedRoundIds']);
        self::assertSame('Solo', self::at($state, 'texts.category'));
        self::assertArrayHasKey('mercure', $state);
        self::assertIsInt($state['serverNowMs']);

        // The stopwatch is the public stopwatch payload of the same round
        $stopwatch = self::getContainer()->get(GetRoundStopwatchState::class)->forRound(OfficialResultsFixture::ROUND_GROUP_A)->jsonSerialize();
        self::assertIsArray($state['stopwatch']);
        self::assertSame(array_keys($stopwatch), array_keys($state['stopwatch']));
        self::assertSame($stopwatch['stateHash'], self::at($state, 'stopwatch.stateHash'));
    }

    public function testADesignWithoutResultsElementsGetsNoResultsAtAll(): void
    {
        $browser = self::createClient();
        $token = self::createDisplay(OfficialResultsFixture::COMPETITION_RESULTS_CUP, [['type' => 'timer'], ['type' => 'puzzle']], OfficialResultsFixture::ROUND_GROUP_A);

        [$raw, $state] = self::displayState($browser, $token);

        self::assertNull($state['results']);
        foreach (['Anna Fast', 'Ben Steady', 'Gina Quick'] as $name) {
            self::assertStringNotContainsString($name, $raw);
        }
    }

    public function testPublishedResultsShowOnlyOnceTheRoundIsPublished(): void
    {
        $browser = self::createClient();
        $elements = [self::LEADERBOARD, self::FINISHED, self::TICKER];

        // Group A is published: ranked rows, did not start and no result left out
        [$raw, $state] = self::displayState($browser, self::createDisplay(OfficialResultsFixture::COMPETITION_RESULTS_CUP, $elements, OfficialResultsFixture::ROUND_GROUP_A));
        $results = $state['results'];
        self::assertIsArray($results);
        self::assertTrue($results['published']);
        self::assertSame(4, $results['finished']);
        self::assertSame(5, $results['total']);
        self::assertSame([[1, 'Anna Fast'], [2, 'Ben Steady'], [2, 'Cara Tied'], [4, 'Dan Unfinished']], self::ranksAndNames($results['top']));
        self::assertSame(['cz'], self::at($results, 'top.0.countries'));
        self::assertSame(3600, self::at($results, 'top.0.resultSeconds'));
        self::assertSame(1, self::at($results, 'top.0.tableNumber'));
        self::assertTrue(self::at($results, 'top.3.didNotFinish'));
        self::assertSame(850, self::at($results, 'top.3.piecesPlaced'));
        self::assertCount(2, (array) $results['latest']);
        self::assertStringNotContainsString('Eva Noshow', $raw);
        self::assertStringNotContainsString('Filip Pending', $raw);

        // Group B is not published: nothing of it with published sources
        [$raw, $state] = self::displayState($browser, self::createDisplay(OfficialResultsFixture::COMPETITION_RESULTS_CUP, $elements, OfficialResultsFixture::ROUND_GROUP_B));
        self::assertSame(['published' => false, 'finished' => null, 'total' => null, 'top' => [], 'latest' => []], $state['results']);
        self::assertStringNotContainsString('Gina Quick', $raw);
        self::assertStringNotContainsString('Hugo Slow', $raw);
    }

    public function testLiveResultsShowBeforePublishingOnlyForTheElementTypeSwitchedToLive(): void
    {
        $browser = self::createClient();
        $token = self::createDisplay(OfficialResultsFixture::COMPETITION_RESULTS_CUP, [self::LIVE_LEADERBOARD, self::FINISHED, self::TICKER], OfficialResultsFixture::ROUND_GROUP_B);

        [$raw, $state] = self::displayState($browser, $token);
        $results = $state['results'];
        self::assertIsArray($results);

        self::assertFalse($results['published']);
        self::assertSame([[1, 'Gina Quick'], [2, 'Hugo Slow'], [3, 'Ivan Last']], self::ranksAndNames($results['top']));
        // The finished count and the ticker stay on "published"
        self::assertNull($results['finished']);
        self::assertSame([], $results['latest']);

        // Gina is the private PLAYER_PRIVATE: the organiser's name only, nothing of her profile
        self::assertStringNotContainsString('Jane Smith', $raw);
        self::assertNoIdentifiers($raw);
    }

    public function testPairRowsCarryTheirMembersAndNoIdentifiers(): void
    {
        $browser = self::createClient();
        $token = self::createDisplay(OfficialResultsFixture::COMPETITION_RESULTS_CUP, [self::LIVE_LEADERBOARD], OfficialResultsFixture::ROUND_PAIRS);

        [$raw, $state] = self::displayState($browser, $token);
        $top = self::at($state, 'results.top');
        self::assertIsArray($top);

        self::assertSame([[1, 'Puzzle Sharks'], [2, 'Edge Hunters'], [3, 'Corner Pieces']], self::ranksAndNames($top));
        self::assertSame(['Anna Fast', 'Ben Steady'], self::at($top, '0.members'));
        self::assertSame(['cz', 'de'], self::at($top, '0.countries'));
        self::assertSame(['Gina Quick', 'Hugo Slow'], self::at($top, '1.members'));
        self::assertStringNotContainsString('Jane Smith', $raw);
        self::assertNoIdentifiers($raw);

        // Keys are opaque and per display: another display's keys for the same rows differ
        [, $other] = self::displayState($browser, self::createDisplay(OfficialResultsFixture::COMPETITION_RESULTS_CUP, [self::LIVE_LEADERBOARD], OfficialResultsFixture::ROUND_PAIRS));
        self::assertMatchesRegularExpression('/^"[0-9a-f]{16}"$/', (string) json_encode(self::at($top, '0.key')));
        self::assertNotSame(self::at($top, '0.key'), self::at($other, 'results.top.0.key'));
    }

    public function testFollowingTheCurrentRound(): void
    {
        $browser = self::createClient();
        $connection = self::getContainer()->get(Connection::class);

        // Every round started 10 days ago: the last one by start
        [, $state] = self::displayState($browser, self::createDisplay(OfficialResultsFixture::COMPETITION_RESULTS_CUP));
        self::assertSame('Pairs Final', self::at($state, 'round.name'));
        self::assertCount(5, (array) $state['followedRoundIds']);

        // Among the chosen rounds only (parallel halls)
        [, $state] = self::displayState($browser, self::createDisplay(OfficialResultsFixture::COMPETITION_RESULTS_CUP, followRoundIds: [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B]));
        self::assertSame('Group B', self::at($state, 'round.name'));
        self::assertSame([OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_GROUP_B], $state['followedRoundIds']);

        // A running stopwatch beats everything
        $connection->executeStatement(
            "UPDATE competition_round SET stopwatch_status = 'running', stopwatch_started_at = " . TestClock::SQL_NOW . " - INTERVAL '5 minutes' WHERE id = :id",
            ['id' => OfficialResultsFixture::ROUND_GROUP_A],
        );
        [, $state] = self::displayState($browser, self::createDisplay(OfficialResultsFixture::COMPETITION_RESULTS_CUP));
        self::assertSame('Group A', self::at($state, 'round.name'));
        self::assertSame('running', self::at($state, 'stopwatch.status'));

        // A pinned round that was deleted (SET NULL) falls back to the current one
        $token = self::createDisplay(OfficialResultsFixture::COMPETITION_RESULTS_CUP, pinnedRoundId: OfficialResultsFixture::ROUND_FINAL);
        $connection->executeStatement('UPDATE competition_display SET pinned_round_id = NULL WHERE token = :token', ['token' => $token]);
        [, $state] = self::displayState($browser, $token);
        self::assertSame('Group A', self::at($state, 'round.name'));
    }

    public function testADraftEventsDisplayWorksByItsToken(): void
    {
        $browser = self::createClient();
        $token = self::createDisplay(OfficialResultsFixture::COMPETITION_RESULTS_CUP, pinnedRoundId: OfficialResultsFixture::ROUND_GROUP_A);
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition SET is_draft = true WHERE id = :id',
            ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP],
        );

        [, $state] = self::displayState($browser, $token);

        self::assertSame('Results Cup', self::at($state, 'competition.name'));
    }

    public function testARegeneratedTokenIs404AtOnce(): void
    {
        $browser = self::createClient();
        $token = self::createDisplay(OfficialResultsFixture::COMPETITION_RESULTS_CUP, [self::LEADERBOARD]);
        $displayId = self::getContainer()->get(Connection::class)->fetchOne('SELECT id FROM competition_display WHERE token = :token', ['token' => $token]);
        self::assertIsString($displayId);

        $browser->request('GET', '/display/' . $token . '/design');
        self::assertResponseIsSuccessful();
        $design = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($design);
        self::assertSame(1, $design['designVersion']);
        self::assertSame('leaderboard', self::at($design, 'design.elements.0.type'));
        self::assertSame('no-store, private', $browser->getResponse()->headers->get('Cache-Control'));

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new RegenerateCompetitionDisplayToken($displayId));

        foreach (['/state', '/design'] as $part) {
            $browser->request('GET', '/display/' . $token . $part);
            self::assertResponseStatusCodeSame(404, $part);
        }
    }

    /**
     * Statement budget (D §7.2): display + event, rounds, visible puzzles - and the entries only when results may show
     */
    public function testTheStateCostsAFixedNumberOfStatements(): void
    {
        $browser = self::createClient();
        $withResults = self::createDisplay(OfficialResultsFixture::COMPETITION_RESULTS_CUP, [self::LEADERBOARD, self::FINISHED, self::TICKER], OfficialResultsFixture::ROUND_GROUP_A);
        $withoutResults = self::createDisplay(OfficialResultsFixture::COMPETITION_RESULTS_CUP, [['type' => 'timer']], OfficialResultsFixture::ROUND_GROUP_A);
        $notYetPublished = self::createDisplay(OfficialResultsFixture::COMPETITION_RESULTS_CUP, [self::LEADERBOARD], OfficialResultsFixture::ROUND_GROUP_B);

        foreach ([[$withResults, 4], [$withoutResults, 3], [$notYetPublished, 3]] as [$token, $statements]) {
            $this->startCountingQueries($browser);
            self::displayState($browser, $token);
            self::assertSame($statements, $this->queryCount($browser));
        }
    }

    private static function assertNoIdentifiers(string $raw): void
    {
        // Fixture ids (participants, entries, teams) and every player id or #code of the fixture's people
        self::assertDoesNotMatchRegularExpression('/018d0020-0000-0000-0000-0000000002\d\d|018d0020-0000-0000-0000-0000000003\d\d|018d0020-0000-0000-0000-0000000004\d\d/', $raw);

        foreach ([PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR] as $playerId) {
            self::assertStringNotContainsString($playerId, $raw);
        }

        foreach (self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT code FROM player WHERE id IN (:a, :b, :c)', ['a' => PlayerFixture::PLAYER_PRIVATE, 'b' => PlayerFixture::PLAYER_ADMIN, 'c' => PlayerFixture::PLAYER_REGULAR]) as $code) {
            self::assertIsString($code);
            self::assertStringNotContainsStringIgnoringCase('"' . $code . '"', $raw);
            self::assertStringNotContainsStringIgnoringCase('#' . $code, $raw);
        }
    }

    /**
     * @return list<array{0: mixed, 1: mixed}>
     */
    private static function ranksAndNames(mixed $rows): array
    {
        self::assertIsArray($rows);

        return array_values(array_map(static fn (mixed $row): array => is_array($row) ? [$row['rank'] ?? null, $row['name'] ?? null] : [null, null], $rows));
    }
}
