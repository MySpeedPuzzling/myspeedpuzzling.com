<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\CreateCompetitionStanding;
use SpeedPuzzling\Web\Message\PublishCompetitionStanding;
use SpeedPuzzling\Web\Message\UnpublishCompetitionStanding;
use SpeedPuzzling\Web\Query\GetCompetitionStandings;
use SpeedPuzzling\Web\Query\GetPublicStandingHead;
use SpeedPuzzling\Web\Query\GetStandingResults;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayLiveUpdates;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayStandings;
use SpeedPuzzling\Web\Services\OfficialResultsSubscription;
use SpeedPuzzling\Web\Services\Standings\PublicStandingSnapshots;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DisplayKind;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\StandingRules;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The standings element of the displays (competition toolkit v2 S2, displays-standings.md §3.2): the published standing
 * as a guest sees it in the display's state - `auto` and explicit picks, nothing for an unpublished or another event's
 * standing, opaque row keys, texts in the display's language, a fixed statement budget, and a failure that empties the
 * element but never the timer.
 *
 * World Series (WorldSeriesSetup): solo rounds 1-3 published - Dora 2400 s, Bob 2700, Alice 3000, Cyril 3300; pairs
 * rounds 1-3 published - Team Cyril 5400, Alice & Bob 6000.
 */
final class DisplayStandingsTest extends WebTestCase
{
    use CreatesCompetitionDisplays;
    use QueryCountAssertions;
    use WorldSeriesSetup;

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
        $this->createWorldSeries(self::getContainer()->get(EntityManagerInterface::class));
    }

    public function testAutoPicksTheStandingOfTheShownRoundAndAnExplicitOneIsShownAsChosen(): void
    {
        $solo = $this->standing('World Series - Solo', RoundCategory::Solo, $this->soloRounds);
        $pairs = $this->standing('World Series - Pairs', RoundCategory::Duo, $this->pairRounds);

        // The pairs round on screen: auto = the pairs standing; the explicit element shows the solo one
        $token = self::createDisplay(self::WS_COMPETITION, [
            ['type' => 'standings'],
            ['type' => 'standings', 'props' => ['standingId' => strtoupper($solo), 'rows' => 3, 'columns' => ['rank', 'name', 'members', 'total', 'rounds', 'missing']]],
        ], $this->pairRounds[0], kind: DisplayKind::Overlay);
        [$raw, $state] = self::displayState($this->browser, $token);

        self::assertSame('World Series - Pairs', self::at($state, 'standings.el000000.name'));
        self::assertSame([[1, 'Team Cyril', 5400], [2, 'Alice & Bob', 6000]], self::rows(self::at($state, 'standings.el000000.rows')));
        self::assertSame(['Alice', 'Bob'], self::at($state, 'standings.el000000.rows.1.members'));

        $explicit = self::at($state, 'standings.el000001');
        self::assertIsArray($explicit);
        self::assertSame('World Series - Solo', $explicit['name']);
        self::assertTrue($explicit['provisional']);
        self::assertFalse($explicit['final']);
        self::assertSame(3, $explicit['roundsCounted']);
        self::assertSame(6, $explicit['roundsTotal']);
        self::assertSame(['name' => 'Solo 4', 'counted' => false], self::at($explicit, 'rounds.3'));
        // Cut to the element's 3 rows; one person has no members
        self::assertSame([[1, 'Dora', 2400], [2, 'Bob', 2700], [3, 'Alice', 3000]], self::rows($explicit['rows']));
        self::assertSame([], self::at($explicit, 'rows.0.members'));
        self::assertSame(['status' => 'finished', 'scoreSeconds' => 800, 'missingPieces' => 0], self::at($explicit, 'rows.0.perRound.0'));
        self::assertSame(['status' => 'not_counted', 'scoreSeconds' => null, 'missingPieces' => 0], self::at($explicit, 'rows.0.perRound.5'));
        self::assertSame(['key', 'rank', 'complete', 'name', 'members', 'countries', 'totalSeconds', 'totalTimeSeconds', 'totalMissingPieces', 'totalPenaltySeconds', 'roundsScored', 'perRound'], array_keys((array) self::at($explicit, 'rows.0')));

        // Opaque keys (16 hex, per display), no ids, no #codes
        $key = self::at($explicit, 'rows.0.key');
        self::assertIsString($key);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $key);
        self::assertStringNotContainsString($solo, $raw);
        self::assertStringNotContainsString($pairs, $raw);
        self::assertDoesNotMatchRegularExpression('/018d00a6-0000-0000-0000-0000000002\d\d/', $raw);
        self::assertDoesNotMatchRegularExpression('/"[plt]:/', $raw);
        self::assertStringNotContainsString('playerCode', $raw);

        // The texts of the public standing page, in the display's language; plural ones per number shown
        self::assertSame('Provisional', self::at($state, 'texts.standingProvisional'));
        self::assertSame('%scored% of %count% rounds', self::at($state, 'texts.standingRoundsOf'));
        self::assertSame('R%number%', self::at($state, 'texts.standingRoundShort'));
        self::assertSame(['6' => 'Final - all 6 rounds'], self::at($state, 'texts.standingFinal'));
        self::assertSame([], self::at($state, 'texts.standingMissing'));

        // Another display shows the same rows under other keys
        [, $other] = self::displayState($this->browser, self::createDisplay(self::WS_COMPETITION, [['type' => 'standings', 'props' => ['standingId' => $solo]]]));
        self::assertNotSame(self::at($explicit, 'rows.0.key'), self::at($other, 'standings.el000000.rows.0.key'));
    }

    public function testAutoFallsBackToTheRoundsCategoryThenToTheFirstPublishedStanding(): void
    {
        // Only rounds 1-2 counted: round 3 of pairs is in no standing - its category's standing is shown
        $this->standing('World Series - Solo', RoundCategory::Solo, array_slice($this->soloRounds, 0, 2));
        $this->standing('World Series - Pairs', RoundCategory::Duo, array_slice($this->pairRounds, 0, 2));

        [, $state] = self::displayState($this->browser, self::createDisplay(self::WS_COMPETITION, [['type' => 'standings']], $this->pairRounds[2]));
        self::assertSame('World Series - Pairs', self::at($state, 'standings.el000000.name'));

        // A display of an event round in no category's standing: the first published one (by position)
        $this->database->executeStatement("UPDATE competition_round SET category = 'team' WHERE id = :id", ['id' => $this->pairRounds[2]]);
        [, $state] = self::displayState($this->browser, self::createDisplay(self::WS_COMPETITION, [['type' => 'standings']], $this->pairRounds[2]));
        self::assertSame('World Series - Solo', self::at($state, 'standings.el000000.name'));
    }

    public function testNothingForAnUnpublishedAnotherEventsOrAnUnknownStanding(): void
    {
        $solo = $this->standing('World Series - Solo', RoundCategory::Solo, $this->soloRounds, publish: false);
        $cupStanding = Uuid::uuid7();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new CreateCompetitionStanding($cupStanding, OfficialResultsFixture::COMPETITION_RESULTS_CUP, 'Cup', RoundCategory::Solo, [OfficialResultsFixture::ROUND_GROUP_A], new StandingRules()));
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new PublishCompetitionStanding($cupStanding->toString(), OfficialResultsFixture::COMPETITION_RESULTS_CUP));

        $token = self::createDisplay(self::WS_COMPETITION, [
            ['type' => 'standings'],
            ['type' => 'standings', 'props' => ['standingId' => $solo]],
            ['type' => 'standings', 'props' => ['standingId' => $cupStanding->toString()]],
            ['type' => 'standings', 'props' => ['standingId' => Uuid::uuid7()->toString()]],
            ['type' => 'timer'],
        ], $this->soloRounds[0]);
        [$raw, $state] = self::displayState($this->browser, $token);

        self::assertSame(['el000000' => null, 'el000001' => null, 'el000002' => null, 'el000003' => null], $state['standings']);
        self::assertStringNotContainsString('Anna Fast', $raw);
        self::assertSame([], self::at($state, 'texts.standingFinal'));
        self::assertNotNull($state['stopwatch']);

        // Published - shown; unpublished again - nothing
        $this->publish($solo);
        [, $state] = self::displayState($this->browser, $token);
        self::assertSame('World Series - Solo', self::at($state, 'standings.el000000.name'));
        self::assertSame('World Series - Solo', self::at($state, 'standings.el000001.name'));

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new UnpublishCompetitionStanding($solo, self::WS_COMPETITION));
        [, $state] = self::displayState($this->browser, $token);
        self::assertNull(self::at($state, 'standings.el000000'));
    }

    public function testADesignWithoutAStandingsElementKeepsItsAnswer(): void
    {
        $this->standing('World Series - Solo', RoundCategory::Solo, $this->soloRounds);
        [, $state] = self::displayState($this->browser, self::createDisplay(self::WS_COMPETITION, [['type' => 'timer'], ['type' => 'leaderboard']], $this->soloRounds[0]));

        self::assertArrayNotHasKey('standings', $state);
        self::assertIsArray($state['texts']);
        self::assertSame(['startsIn', 'timesUp', 'stopped', 'notStarted', 'scheduled', 'finished', 'piecesPlaced', 'category', 'table', 'pieces', 'invalid'], array_keys($state['texts']));

        // A hidden standings element asks for nothing either
        [, $state] = self::displayState($this->browser, self::createDisplay(self::WS_COMPETITION, [['type' => 'standings', 'hidden' => true]], $this->soloRounds[0]));
        self::assertArrayNotHasKey('standings', $state);
    }

    public function testTheMissingPiecesAreTheT6TotalAndTheirTextIsGivenPerCount(): void
    {
        // Round 6 published: Bob did not finish it (450 of 500 pieces) - the limit + 2 s per missing piece
        $this->database->executeStatement('UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id IN (:a, :b, :c)', ['a' => $this->soloRounds[3], 'b' => $this->soloRounds[4], 'c' => $this->soloRounds[5]]);
        $this->standing('World Series - Solo', RoundCategory::Solo, $this->soloRounds, new StandingRules(2));

        [, $state] = self::displayState($this->browser, self::createDisplay(self::WS_COMPETITION, [['type' => 'standings', 'props' => ['columns' => ['rank', 'name', 'total', 'missing']]]], $this->soloRounds[5]));
        $bob = null;

        foreach ((array) self::at($state, 'standings.el000000.rows') as $row) {
            if (is_array($row) && $row['name'] === 'Bob') {
                $bob = $row;
            }
        }

        self::assertIsArray($bob);
        self::assertSame(50, $bob['totalMissingPieces']);
        self::assertSame(100, $bob['totalPenaltySeconds']);
        self::assertIsInt($bob['totalTimeSeconds']);
        self::assertSame($bob['totalTimeSeconds'] + 100, $bob['totalSeconds']);
        self::assertSame('unfinished', self::at($bob, 'perRound.5.status'));
        self::assertTrue(self::at($state, 'standings.el000000.final'));
        self::assertSame(['50' => '50 pieces missing'], self::at($state, 'texts.standingMissing'));
        self::assertSame(['6' => 'Final - all 6 rounds'], self::at($state, 'texts.standingFinal'));
    }

    public function testTheTextsAreInTheDisplaysLanguage(): void
    {
        $this->standing('World Series - Solo', RoundCategory::Solo, $this->soloRounds);
        $token = self::createDisplay(self::WS_COMPETITION, [['type' => 'standings']], $this->soloRounds[0]);
        $this->database->executeStatement("UPDATE competition_display SET locale = 'cs' WHERE token = :token", ['token' => $token]);

        [, $state] = self::displayState($this->browser, $token);
        self::assertSame('Průběžné', self::at($state, 'texts.standingProvisional'));
        self::assertSame(['6' => 'Konečné - všech 6 kol'], self::at($state, 'texts.standingFinal'));
    }

    /**
     * Statements (§3.2): the display's own 3 stay, the standing is read once per generation of the event's standings
     * (warm +0; cold: list + the standing's event, head, rounds, entries).
     */
    public function testTheStatementBudget(): void
    {
        $this->standing('World Series - Solo', RoundCategory::Solo, $this->soloRounds);
        $token = self::createDisplay(self::WS_COMPETITION, [['type' => 'standings'], ['type' => 'timer']], $this->soloRounds[0]);
        $this->browser->disableReboot();
        self::getContainer()->set('competition_standings_cache', new FilesystemAdapter('s2_' . bin2hex(random_bytes(6)), 0, sys_get_temp_dir() . '/display-standings-test'));

        $this->startCountingQueries($this->browser);
        self::displayState($this->browser, $token);
        $this->assertQueryCountAtMost($this->browser, 3 + 5, 'cold');

        $this->startCountingQueries($this->browser);
        self::displayState($this->browser, $token);
        self::assertSame(3, $this->queryCount($this->browser), 'warm');

        // Publishing a standing (its controller's after-commit hook) = a new generation: the next state reads it again
        self::getContainer()->get(CompetitionDisplayLiveUpdates::class)->standingsChanged(self::WS_COMPETITION);
        $this->startCountingQueries($this->browser);
        self::displayState($this->browser, $token);
        self::assertGreaterThan(3, $this->queryCount($this->browser), 'after a new generation');
    }

    /**
     * A standing written (published, unpublished, edited) = the guest snapshots' new generation first, then a
     * `standings` signal to the event's open displays (displays-standings.md §3.2).
     */
    public function testAStandingWriteBumpsTheGenerationThenSignalsTheDisplays(): void
    {
        $this->standing('World Series - Solo', RoundCategory::Solo, $this->soloRounds);
        self::createDisplay(self::WS_COMPETITION, [['type' => 'standings']]);
        $this->browser->disableReboot();
        self::getContainer()->set('competition_standings_cache', new FilesystemAdapter('s2_' . bin2hex(random_bytes(6)), 0, sys_get_temp_dir() . '/display-standings-test'));

        $snapshots = self::getContainer()->get(PublicStandingSnapshots::class);
        $before = $snapshots->generation(self::WS_COMPETITION);
        $hub = self::getContainer()->get(NullMercureHub::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        assert($hub instanceof NullMercureHub);

        // Through the controller the organiser uses
        TestingLogin::asPlayer($this->browser, self::WS_ORGANISER);
        $crawler = $this->browser->request('GET', '/en/manage-event-standings/' . self::WS_COMPETITION);
        self::assertResponseIsSuccessful();
        $this->browser->submit($crawler->filter('[data-standing-unpublish]')->first()->form());
        self::assertResponseRedirects();

        $signals = array_values(array_map(
            static fn (Update $update): mixed => json_decode($update->getData(), true),
            array_filter($hub->getPublishedUpdates(), static fn (Update $update): bool => in_array(OfficialResultsSubscription::displayTopic(self::WS_COMPETITION), $update->getTopics(), true)),
        ));
        self::assertSame([['type' => 'display.signal', 'what' => 'standings']], $signals);
        self::assertNotSame($before, $snapshots->generation(self::WS_COMPETITION));
    }

    /**
     * A standings failure never blanks the timer: the element is empty (null) and a warning says why.
     */
    public function testAFailureEmptiesTheElementWithAWarning(): void
    {
        $broken = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'port' => 1, 'dbname' => 'none', 'user' => 'none']);
        $container = self::getContainer();
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }
        };

        $standings = new DisplayStandings(new PublicStandingSnapshots(
            new GetPublicStandingHead($broken),
            $container->get(GetStandingResults::class),
            new GetCompetitionStandings($broken),
            new ArrayAdapter(),
            $container->get(ClockInterface::class),
            $logger,
        ), $logger);

        $design = ['elements' => [
            ['id' => 'stand001', 'type' => 'standings', 'hidden' => false, 'when' => ['phases' => ['running']], 'props' => ['standingId' => 'auto', 'rows' => 5]],
            ['id' => 'stand002', 'type' => 'standings', 'hidden' => false, 'when' => ['phases' => ['running']], 'props' => ['standingId' => Uuid::uuid7()->toString(), 'rows' => 5]],
        ]];

        self::assertSame(['stand001' => null, 'stand002' => null], $standings->forDisplay($design, self::WS_COMPETITION, ['id' => $this->soloRounds[0], 'category' => 'solo'], 'token'));
        self::assertContains('Could not read the standings of a display - shown empty', $logger->warnings);
        self::assertNull($standings->forDisplay(['elements' => []], self::WS_COMPETITION, null, 'token'));
    }

    /**
     * @param list<string> $rounds
     */
    private function standing(string $name, RoundCategory $category, array $rounds, StandingRules $rules = new StandingRules(), bool $publish = true): string
    {
        $id = Uuid::uuid7();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new CreateCompetitionStanding($id, self::WS_COMPETITION, $name, $category, $rounds, $rules));

        if ($publish) {
            $this->publish($id->toString());
        }

        return $id->toString();
    }

    private function publish(string $standingId): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new PublishCompetitionStanding($standingId, self::WS_COMPETITION));
    }

    /**
     * @return list<array{0: mixed, 1: mixed, 2: mixed}>
     */
    private static function rows(mixed $rows): array
    {
        self::assertIsArray($rows);

        return array_values(array_map(static fn (mixed $row): array => is_array($row) ? [$row['rank'] ?? null, $row['name'] ?? null, $row['totalSeconds'] ?? null] : [null, null, null], $rows));
    }
}
