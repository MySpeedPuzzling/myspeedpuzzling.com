<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Sandbox;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\SandboxModeIsOff;
use SpeedPuzzling\Web\Message\CreateSandboxPlayers;
use SpeedPuzzling\Web\MessageHandler\CreateSandboxPlayersHandler;
use SpeedPuzzling\Web\Query\GetCompetitionDivisions;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Query\GetSandboxDemoData;
use SpeedPuzzling\Web\Query\GetSandboxSimulationData;
use SpeedPuzzling\Web\Query\RoundPuzzlesVisibleNow;
use SpeedPuzzling\Web\Results\VisibleRoundPuzzle;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Results\CompetitionDivisionOverview;
use SpeedPuzzling\Web\Services\GenerateUniquePlayerCode;
use SpeedPuzzling\Web\Services\SameTablesCopy;
use SpeedPuzzling\Web\Services\Sandbox\SandboxDemoEventDefinitions;
use SpeedPuzzling\Web\Services\Sandbox\SandboxDemoEvents;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMode;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The sandbox's demo events (docs/features/sandbox/README.md "Demo events") built on the test fixtures: the sandbox
 * guard is bypassed the documented way - the service gets `new SandboxMode(true)` (like CreateSandboxPlayersHandlerTest),
 * the messages it dispatches are ordinary ones. A second run adds nothing.
 */
final class SandboxDemoEventsTest extends KernelTestCase
{
    private const string DAY = '2026-12-02';

    public function testBuildsBothEventsAndARerunAddsNothing(): void
    {
        self::bootKernel();
        $database = self::getContainer()->get(Connection::class);
        // 40 made-up players: the organiser, the referee and 38 participants linked to profiles - the rest are guests
        $this->createMadeUpPlayers(40);

        $simulationData = self::getContainer()->get(GetSandboxSimulationData::class);
        $organizer = $simulationData->playerIdByEmail('test-user+1@myspeedpuzzling.com');
        $referee = $simulationData->playerIdByEmail('test-user+2@myspeedpuzzling.com');
        self::assertNotNull($organizer);
        self::assertNotNull($referee);

        $lines = [];
        $this->service(true)->setUp(self::DAY, $organizer, $referee, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $spws = $this->competitionId(SandboxDemoEventDefinitions::SPWS_SLUG);
        $carnival = $this->competitionId(SandboxDemoEventDefinitions::CARNIVAL_SLUG);

        // The event: approved, public, the organiser on its team, the referee added, competitor pages on
        $event = $database->fetchAssociative('SELECT approved_at IS NOT NULL AS approved, is_draft, competitor_pages, location_country_code FROM competition WHERE id = :id', ['id' => $spws]);
        self::assertSame(['approved' => true, 'is_draft' => false, 'competitor_pages' => true, 'location_country_code' => 'ca'], $event);
        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM competition_maintainer WHERE competition_id = :id AND player_id = :player', ['id' => $spws, 'player' => $organizer]));
        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM competition_referee WHERE competition_id = :id AND player_id = :player', ['id' => $spws, 'player' => $referee]));

        // Rounds: a block of challenges is one "Solve all 2" round with its labels, challenge 5 unboxed
        self::assertSame(10, $this->rowCount('SELECT COUNT(*) FROM competition_round WHERE competition_id = :id', ['id' => $spws]));
        self::assertSame(7, $this->rowCount('SELECT COUNT(*) FROM competition_round WHERE competition_id = :id', ['id' => $carnival]));
        $block = $this->round($spws, 'Pairs - Challenges 1 + 2');
        self::assertSame(['puzzle_format' => 'pick', 'puzzles_to_solve' => 2, 'category' => 'duo', 'minutes_limit' => 90, 'unboxed' => false], array_intersect_key($block, array_flip(['puzzle_format', 'puzzles_to_solve', 'category', 'minutes_limit', 'unboxed'])));
        self::assertSame(['Challenge 1', 'Challenge 2'], $database->fetchFirstColumn('SELECT label FROM competition_round_puzzle WHERE round_id = :id ORDER BY position', ['id' => $block['id']]));
        self::assertTrue($this->round($spws, 'Pairs - Challenge 5')['unboxed']);
        // 10:30 in Toronto on the day before the demo day
        self::assertSame('2026-12-01 15:30:00', $block['starts_at']);

        // Participants: 24 pairs + 12 teams of 4 + 30 individuals; 38 linked to made-up players
        self::assertSame(126, $this->rowCount('SELECT COUNT(*) FROM competition_participant WHERE competition_id = :id AND deleted_at IS NULL', ['id' => $spws]));
        self::assertSame(38, $this->rowCount('SELECT COUNT(*) FROM competition_participant WHERE competition_id = :id AND player_id IS NOT NULL', ['id' => $spws]));
        self::assertSame(24, $this->rowCount('SELECT COUNT(*) FROM competition_team WHERE round_id = :id', ['id' => $block['id']]));

        // Tables on the first block, the same tables copied to the next one
        self::assertSame(range(1, 24), array_map(static fn (mixed $number): int => is_numeric($number) ? (int) $number : 0, $database->fetchFirstColumn('SELECT table_number FROM competition_team WHERE round_id = :id ORDER BY table_number', ['id' => $block['id']])));
        $nextBlock = $this->round($spws, 'Pairs - Challenges 3 + 4');
        self::assertSame(24, $this->rowCount(<<<SQL
SELECT COUNT(*)
FROM competition_team a
JOIN competition_team b ON b.round_id = :next AND b.name = a.name
WHERE a.round_id = :first AND a.table_number = b.table_number
SQL, ['first' => $block['id'], 'next' => $nextBlock['id']]));

        // The first block recorded and published: a no-show, a time's up, two finished with pieces missing
        self::assertNotNull($block['results_published_at']);
        self::assertSame(24, $this->teamResults($block['id']));
        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM competition_team WHERE round_id = :id AND result_did_not_start', ['id' => $block['id']]));
        self::assertGreaterThanOrEqual(1, $this->rowCount('SELECT COUNT(*) FROM competition_team WHERE round_id = :id AND result_pieces_placed IS NOT NULL', ['id' => $block['id']]));
        self::assertSame(2, $this->rowCount('SELECT COUNT(*) FROM competition_team WHERE round_id = :id AND result_missing_pieces IS NOT NULL', ['id' => $block['id']]));
        self::assertSame(22, $this->rowCount("SELECT COUNT(*) FROM competition_team WHERE round_id = :id AND json_array_length(result_puzzles::json) = 2 AND result_seconds IS NOT NULL", ['id' => $block['id']]));

        // The second block running: some results, some first splits only, unpublished
        self::assertNull($nextBlock['results_published_at']);
        self::assertSame(8, $this->teamResults($nextBlock['id']));
        self::assertGreaterThan(0, $this->rowCount('SELECT COUNT(*) FROM competition_team WHERE round_id = :id AND result_puzzles IS NOT NULL AND result_seconds IS NULL AND result_pieces_placed IS NULL', ['id' => $nextBlock['id']]));
        self::assertSame(0, $this->teamResults($this->round($spws, 'Pairs - Challenge 5')['id']));

        // Individuals: the first challenge published with a result for everybody
        self::assertSame(30, $this->rowCount('SELECT COUNT(*) FROM competition_participant_round WHERE round_id = :id AND (result_seconds IS NOT NULL OR result_pieces_placed IS NOT NULL OR result_did_not_start)', ['id' => $this->round($spws, 'Individual - Challenge 1')['id']]));

        // One World Series standing per championship, published; two displays
        $standings = $database->fetchAllAssociative('SELECT name, unfinished_penalty_seconds_per_piece AS penalty, order_rule, missing_rule, penalise_finished_missing, published_at IS NOT NULL AS published FROM competition_standing WHERE competition_id = :id ORDER BY name', ['id' => $spws]);
        self::assertSame(['Individual championship', 'Pairs championship', 'Teams championship'], array_column($standings, 'name'));

        foreach ($standings as $standing) {
            self::assertSame([5, 'total_only', 'as_zero_pieces', true, true], [is_numeric($standing['penalty']) ? (int) $standing['penalty'] : null, $standing['order_rule'], $standing['missing_rule'], $standing['penalise_finished_missing'], $standing['published']]);
        }

        self::assertSame(['Challenges board', 'Hall timer screen', 'Stream overlay'], $database->fetchFirstColumn('SELECT name FROM competition_display WHERE competition_id = :id ORDER BY name', ['id' => $spws]));
        // Real events L2b: the challenges board pinned to the first block, one leaderboard per challenge
        $board = $database->fetchAssociative("SELECT design::text AS design, pinned_round_id FROM competition_display WHERE competition_id = :id AND name = 'Challenges board'", ['id' => $spws]);
        self::assertIsArray($board);
        self::assertSame($this->round($spws, 'Pairs - Challenges 1 + 2')['id'], $board['pinned_round_id']);
        self::assertIsString($board['design']);
        self::assertStringContainsString('"challenge": 1', $board['design']);
        self::assertStringContainsString('"challenge": 2', $board['design']);
        $overlayDesign = $database->fetchOne("SELECT design::text FROM competition_display WHERE competition_id = :id AND name = 'Stream overlay'", ['id' => $spws]);
        self::assertIsString($overlayDesign);
        self::assertStringContainsString('"standings"', $overlayDesign);

        // Carnival: divisions on the event, the rounds' subsets and the entries
        $divisions = self::getContainer()->get(GetCompetitionDivisions::class)->ofCompetition($carnival);
        self::assertSame([SandboxDemoEventDefinitions::REGULAR, SandboxDemoEventDefinitions::SENIOR, SandboxDemoEventDefinitions::JUNIOR], array_map(static fn (CompetitionDivisionOverview $division): string => $division->name, $divisions));
        $solo = $this->round($carnival, '500 SOLO');
        self::assertSame(25, $this->rowCount('SELECT COUNT(*) FROM competition_participant_round WHERE round_id = :id AND division_id IS NOT NULL', ['id' => $solo['id']]));
        self::assertGreaterThan(0, $this->rowCount("SELECT COUNT(*) FROM competition_participant_round cpr JOIN competition_division d ON d.id = cpr.division_id WHERE cpr.round_id = :id AND d.name = :junior", ['id' => $solo['id'], 'junior' => SandboxDemoEventDefinitions::JUNIOR]));
        self::assertSame(16, $this->rowCount('SELECT COUNT(*) FROM competition_team WHERE round_id = :id AND division_id IS NOT NULL', ['id' => $this->round($carnival, '500 PAIRS')['id']]));

        // Real events L2b: the divisions board pinned to SOLO (3 divisions), one leaderboard per division
        $board = $database->fetchAssociative("SELECT design::text AS design, pinned_round_id FROM competition_display WHERE competition_id = :id AND name = 'Divisions board'", ['id' => $carnival]);
        self::assertIsArray($board);
        self::assertSame($solo['id'], $board['pinned_round_id']);
        self::assertIsString($board['design']);
        foreach ($divisions as $division) {
            self::assertStringContainsString('"division": "' . strtolower($division->id) . '"', $board['design']);
        }

        // Carnival Saturday published; OPEN #1 shares CHALLENGE's puzzle (shared puzzles S2)
        self::assertNotNull($this->round($carnival, '1000 Teams')['results_published_at']);
        $open = $this->round($carnival, '500 Teams OPEN #1');
        self::assertSame(20, $this->teamResults($open['id']));
        $shared = $this->rowCount(<<<SQL
SELECT COUNT(*)
FROM competition_round_puzzle a
JOIN competition_round_puzzle b ON b.puzzle_id = a.puzzle_id AND b.round_id = :open
WHERE a.round_id = :challenge
SQL, ['open' => $open['id'], 'challenge' => $this->round($carnival, '500 Teams CHALLENGE')['id']]);

        self::assertSame(1, $shared);
        self::assertSame([], array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'TODO:'))));

        self::assertNotEmpty(array_filter($lines, static fn (string $line): bool => str_contains($line, '/en/events/' . SandboxDemoEventDefinitions::SPWS_SLUG)));
        // The old demos start no stopwatch (stopwatchFromStart is the SPWS demo's only)
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM competition_round cr JOIN competition c ON c.id = cr.competition_id WHERE c.slug IN (:a, :b) AND cr.stopwatch_status IS NOT NULL', ['a' => SandboxDemoEventDefinitions::SPWS_SLUG, 'b' => SandboxDemoEventDefinitions::CARNIVAL_SLUG]));

        // Again: nothing new, nothing changed
        $snapshot = $this->snapshot();
        $this->service(true)->setUp(self::DAY, $organizer, $referee, static function (string $line): void {
        });
        self::assertSame($snapshot, $this->snapshot());
    }

    /**
     * The Speed Puzzle World Series format 1:1 (docs/features/competitions-management/spws-demo-event.md), built only
     * on request: the confirmed rounds, hall table names, a tie, secret puzzles revealed at the stopwatch start, one
     * challenge unboxed on its own, the organization with the extra organisers, the displays in the event's look.
     */
    public function testBuildsTheSpwsTorontoDemoOnRequest(): void
    {
        self::bootKernel();
        $database = self::getContainer()->get(Connection::class);
        $this->createMadeUpPlayers(40);

        $simulationData = self::getContainer()->get(GetSandboxSimulationData::class);
        $organizer = $simulationData->playerIdByEmail('test-user+1@myspeedpuzzling.com');
        $referee = $simulationData->playerIdByEmail('test-user+2@myspeedpuzzling.com');
        // Another organiser - a fixture player who is no made-up participant
        $maintainer = PlayerFixture::PLAYER_REGULAR;
        self::assertNotNull($organizer);
        self::assertNotNull($referee);

        $lines = [];
        $this->service(true)->setUp(self::DAY, $organizer, $referee, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }, SandboxDemoEventDefinitions::SPWS_TORONTO_SLUG, [$maintainer]);

        self::assertSame([], array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'TODO:'))));
        // Only the event asked for
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM competition WHERE slug IN (:a, :b)', ['a' => SandboxDemoEventDefinitions::SPWS_SLUG, 'b' => SandboxDemoEventDefinitions::CARNIVAL_SLUG]));
        $spws = $this->competitionId(SandboxDemoEventDefinitions::SPWS_TORONTO_SLUG);

        // The organisers: the made-up one, the extra one on the event and on the organization's team; the event in it
        self::assertSame(2, $this->rowCount('SELECT COUNT(*) FROM competition_maintainer WHERE competition_id = :id AND player_id IN (:a, :b)', ['id' => $spws, 'a' => $organizer, 'b' => $maintainer]));
        $organization = $database->fetchAssociative("SELECT o.id, o.approved_at IS NOT NULL AS approved FROM organization o JOIN competition c ON c.organization_id = o.id WHERE c.id = :id", ['id' => $spws]);
        self::assertIsArray($organization);
        self::assertTrue($organization['approved']);
        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM organization_maintainer WHERE organization_id = :o AND player_id = :p', ['o' => $organization['id'], 'p' => $maintainer]));

        // The confirmed rounds: limits, local start times (America/Toronto, UTC-5 in winter), blocks of two challenges
        $rounds = $database->fetchAllAssociative('SELECT name, minutes_limit, starts_at, puzzle_format, puzzles_to_solve, unboxed FROM competition_round WHERE competition_id = :id ORDER BY starts_at', ['id' => $spws]);
        self::assertSame([
            ['Pairs - Challenges 1 + 2', 90, '2026-12-01 16:00:00', 'pick', 2, false],
            ['Pairs - Challenges 3 + 4', 90, '2026-12-01 18:30:00', 'pick', 2, false],
            ['Pairs - Challenge 5', 60, '2026-12-01 21:00:00', 'total', 1, true],
            ['Teams - Challenges 1 + 2', 120, '2026-12-02 15:00:00', 'pick', 2, false],
            ['Teams - Challenges 3 + 4', 150, '2026-12-02 20:00:00', 'pick', 2, false],
            ['Individual - Challenge 1', 75, '2026-12-03 16:00:00', 'total', 1, false],
            ['Individual - Challenge 2', 75, '2026-12-03 18:30:00', 'total', 1, false],
            ['Individual - Challenge 3', 75, '2026-12-03 20:45:00', 'total', 1, false],
            ['Individual - Challenge 4', 75, '2026-12-04 16:00:00', 'total', 1, false],
            ['Individual - Challenge 5', 75, '2026-12-04 18:30:00', 'total', 1, true],
        ], array_map(static fn (array $round): array => [$round['name'], $round['minutes_limit'], $round['starts_at'], $round['puzzle_format'], $round['puzzle_format'] === 'pick' ? $round['puzzles_to_solve'] : 1, $round['unboxed']], $rounds));

        // Pairs Challenges 3 + 4 labelled (the 250 piece wooden stand-in needs the real catalogue - any puzzle here)
        $block = $this->round($spws, 'Pairs - Challenges 3 + 4');
        self::assertSame(['Challenge 3', 'Challenge 4'], array_map(
            static fn (array $row): mixed => $row['label'],
            $database->fetchAllAssociative('SELECT crp.label, p.pieces_count FROM competition_round_puzzle crp JOIN puzzle p ON p.id = crp.puzzle_id WHERE crp.round_id = :id ORDER BY crp.position', ['id' => $block['id']]),
        ));

        // Teams Challenges 3 + 4: two new secret puzzles waiting for the stopwatch start, only Challenge 4 unboxed
        $secret = $this->round($spws, 'Teams - Challenges 3 + 4');
        self::assertSame([
            ['Challenge 3', true, 'stopwatch', false, 'Random Cut Challenge (demo stand-in)'],
            ['Challenge 4', true, 'stopwatch', true, 'Unboxed Challenge (demo stand-in)'],
        ], array_map(
            static fn (array $row): array => [$row['label'], $row['hide_until_round_starts'], $row['reveal_mode'], $row['unboxed'], $row['name']],
            $database->fetchAllAssociative('SELECT crp.label, crp.hide_until_round_starts, crp.reveal_mode, crp.unboxed, p.name FROM competition_round_puzzle crp JOIN puzzle p ON p.id = crp.puzzle_id WHERE crp.round_id = :id ORDER BY crp.position', ['id' => $secret['id']]),
        ));

        // Hall table names on every round: two places per table for pairs and individuals, one table per team
        $labels = $database->fetchAllKeyValue('SELECT name, table_labels::text FROM competition_round WHERE competition_id = :id', ['id' => $spws]);

        foreach ($labels as $name => $list) {
            self::assertIsString($list, $name);
            $decoded = json_decode($list, true);
            self::assertIsArray($decoded);
            self::assertSame(str_starts_with((string) $name, 'Teams') ? ['A1', 'A2'] : ['A1 L', 'A1 R'], array_slice($decoded, 0, 2), $name);
            self::assertCount(str_starts_with((string) $name, 'Teams') ? 175 : 350, $decoded, $name);
        }

        // Same tables in the next block of the championship
        $first = $this->round($spws, 'Pairs - Challenges 1 + 2');
        self::assertSame(30, $this->rowCount(<<<SQL
SELECT COUNT(*)
FROM competition_team a
JOIN competition_team b ON b.round_id = :next AND b.name = a.name
WHERE a.round_id = :first AND a.table_number = b.table_number
SQL, ['first' => $first['id'], 'next' => $block['id']]));

        // Pairs published with a tie: two entries share a time
        self::assertNotNull($first['results_published_at']);
        self::assertSame(30, $this->teamResults($first['id']));
        self::assertGreaterThanOrEqual(1, $this->rowCount('SELECT COUNT(*) FROM (SELECT result_seconds FROM competition_team WHERE round_id = :id AND result_seconds IS NOT NULL GROUP BY result_seconds HAVING COUNT(*) >= 2) t', ['id' => $first['id']]));
        // Public pages list an arranged block in its order (Challenge 1 first), never by name
        $byPosition = $database->fetchFirstColumn('SELECT puzzle_id FROM competition_round_puzzle WHERE round_id = :id ORDER BY position', ['id' => $first['id']]);
        self::assertIsString($first['id']);
        $visible = self::getContainer()->get(RoundPuzzlesVisibleNow::class)->forRound($first['id'], new \DateTimeImmutable('2026-12-02 12:00:00'));
        self::assertSame($byPosition, array_map(static fn (VisibleRoundPuzzle $puzzle): string => $puzzle->puzzleId, $visible->puzzles));

        // A no-show in each published round - another pair each time, so every no-show stays ranked in the standing
        self::assertSame(3, $this->rowCount("SELECT COUNT(DISTINCT ct.name) FROM competition_team ct JOIN competition_round cr ON cr.id = ct.round_id WHERE cr.competition_id = :id AND cr.category = 'duo' AND ct.result_did_not_start", ['id' => $spws]));
        // Built on a day still ahead (the test clock is weeks before DAY - like the real dates months ahead): the demo
        // day's running block waits - no results, no stopwatch, so a stopwatch started live today blocks nothing
        self::assertNull($this->round($spws, 'Teams - Challenges 1 + 2')['results_published_at']);
        self::assertSame(0, $this->teamResults($this->round($spws, 'Teams - Challenges 1 + 2')['id']));
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM competition_team ct JOIN competition_round cr ON cr.id = ct.round_id WHERE cr.competition_id = :id AND cr.name = :name AND ct.result_puzzles IS NOT NULL', ['id' => $spws, 'name' => 'Teams - Challenges 1 + 2']));
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM competition_round WHERE competition_id = :id AND stopwatch_status IS NOT NULL', ['id' => $spws]));
        self::assertContains('"Teams - Challenges 1 + 2" is the demo day\'s running block - the day is ahead: no results, not started (start it yourself)', $lines);
        // The individuals are the first 40 team people - one participant each
        self::assertSame(124, $this->rowCount('SELECT COUNT(*) FROM competition_participant WHERE competition_id = :id AND deleted_at IS NULL', ['id' => $spws]));

        self::assertSame(['Individual championship', 'Pairs championship', 'Teams championship'], $database->fetchFirstColumn('SELECT name FROM competition_standing WHERE competition_id = :id ORDER BY name', ['id' => $spws]));
        self::assertSame(
            ['SPWS challenge board', 'SPWS championship standings', 'SPWS hall screen', 'SPWS lower third', 'SPWS stream overlay'],
            $database->fetchFirstColumn('SELECT name FROM competition_display WHERE competition_id = :id ORDER BY name', ['id' => $spws]),
        );
        $hall = $database->fetchOne("SELECT design::text FROM competition_display WHERE competition_id = :id AND name = 'SPWS hall screen'", ['id' => $spws]);
        self::assertIsString($hall);
        self::assertStringContainsString('"competition_logo"', $hall);
        self::assertStringContainsString('"live"', $hall);
        self::assertNotEmpty(array_filter($lines, static fn (string $line): bool => str_contains($line, 'layout=tents&boxes=standing:')));

        // Again: nothing new, nothing changed
        $snapshot = $this->snapshot([SandboxDemoEventDefinitions::SPWS_TORONTO_SLUG]);
        $this->service(true)->setUp(self::DAY, $organizer, $referee, static function (string $line): void {
        }, SandboxDemoEventDefinitions::SPWS_TORONTO_SLUG, [$maintainer]);
        self::assertSame($snapshot, $this->snapshot([SandboxDemoEventDefinitions::SPWS_TORONTO_SLUG]));
    }

    /**
     * Built on the call day after the running block's start (11:30 Toronto): its stopwatch is started at 10:00 - the
     * judge's "Finished now" works at once and every seeded time lies in the clock's past. A rehearsal's stopwatch
     * (stopped) is never touched by a re-run.
     */
    public function testTheRunningBlockOfTheSpwsDemoRunsFromItsStartOnTheCallDay(): void
    {
        self::bootKernel();
        self::getContainer()->set(ClockInterface::class, new MockClock(new \DateTimeImmutable('2026-12-02 16:30:00', new \DateTimeZone('UTC'))));
        $this->createMadeUpPlayers(40);

        $simulationData = self::getContainer()->get(GetSandboxSimulationData::class);
        $organizer = $simulationData->playerIdByEmail('test-user+1@myspeedpuzzling.com');
        $referee = $simulationData->playerIdByEmail('test-user+2@myspeedpuzzling.com');
        self::assertNotNull($organizer);

        $lines = [];
        $this->service(true)->setUp(self::DAY, $organizer, $referee, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }, SandboxDemoEventDefinitions::SPWS_TORONTO_SLUG);

        $database = self::getContainer()->get(Connection::class);
        $spws = $this->competitionId(SandboxDemoEventDefinitions::SPWS_TORONTO_SLUG);
        self::assertSame([['Teams - Challenges 1 + 2', 'running', '2026-12-02 15:00:00']], $database->fetchAllNumeric('SELECT name, stopwatch_status, stopwatch_started_at FROM competition_round WHERE competition_id = :id AND stopwatch_status IS NOT NULL', ['id' => $spws]));
        self::assertContains('Stopwatch of "Teams - Challenges 1 + 2" started at 2026-12-02 10:00 (America/Toronto)', $lines);
        $this->assertSeededWithinTheHour($spws);
        // Teams 3 + 4 (15:00 Toronto) still gets its secret puzzles
        self::assertSame([], array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'TODO:'))));

        // A rehearsal stopped it - a re-run leaves it so
        $database->executeStatement("UPDATE competition_round SET stopwatch_status = 'stopped', stopwatch_stopped_at = '2026-12-02 16:20:00' WHERE competition_id = :id AND name = 'Teams - Challenges 1 + 2'", ['id' => $spws]);
        $this->service(true)->setUp(self::DAY, $organizer, $referee, static function (string $line): void {
        }, SandboxDemoEventDefinitions::SPWS_TORONTO_SLUG);
        self::assertSame('stopped', $database->fetchOne("SELECT stopwatch_status FROM competition_round WHERE competition_id = :id AND name = 'Teams - Challenges 1 + 2'", ['id' => $spws]));
    }

    /**
     * Built on the call day before the block's start (08:00 Toronto): its stopwatch is scheduled for 10:00 Toronto - it
     * runs by itself from then on - and its results are seeded within the first hour.
     */
    public function testTheRunningBlockIsScheduledForItsStartEarlyOnTheCallDay(): void
    {
        self::bootKernel();
        self::getContainer()->set(ClockInterface::class, new MockClock(new \DateTimeImmutable('2026-12-02 13:00:00', new \DateTimeZone('UTC'))));
        $this->createMadeUpPlayers(40);

        $simulationData = self::getContainer()->get(GetSandboxSimulationData::class);
        $organizer = $simulationData->playerIdByEmail('test-user+1@myspeedpuzzling.com');
        $referee = $simulationData->playerIdByEmail('test-user+2@myspeedpuzzling.com');
        self::assertNotNull($organizer);

        $this->service(true)->setUp(self::DAY, $organizer, $referee, static function (string $line): void {
        }, SandboxDemoEventDefinitions::SPWS_TORONTO_SLUG);

        $spws = $this->competitionId(SandboxDemoEventDefinitions::SPWS_TORONTO_SLUG);
        self::assertSame([['Teams - Challenges 1 + 2', 'scheduled', '2026-12-02 15:00:00']], self::getContainer()->get(Connection::class)->fetchAllNumeric('SELECT name, stopwatch_status, stopwatch_started_at FROM competition_round WHERE competition_id = :id AND stopwatch_status IS NOT NULL', ['id' => $spws]));
        $this->assertSeededWithinTheHour($spws);
    }

    /**
     * Teams - Challenges 1 + 2 is seeded only within its first hour (SandboxDemoRound::$seededWithinMinutes): some
     * finished, some with Challenge 1 only, every time ≤ 60 min
     */
    private function assertSeededWithinTheHour(string $competitionId): void
    {
        $round = $this->round($competitionId, 'Teams - Challenges 1 + 2');
        $rows = self::getContainer()->get(Connection::class)->fetchAllAssociative('SELECT result_seconds, result_puzzles::text AS puzzles FROM competition_team WHERE round_id = :id AND (result_seconds IS NOT NULL OR result_puzzles IS NOT NULL)', ['id' => $round['id']]);
        $finished = array_filter($rows, static fn (array $row): bool => $row['result_seconds'] !== null);
        self::assertNotEmpty($finished, 'somebody finished');
        self::assertGreaterThan(count($finished), count($rows), 'somebody has Challenge 1 only');

        foreach ($rows as $row) {
            self::assertTrue($row['result_seconds'] === null || (is_int($row['result_seconds']) && $row['result_seconds'] <= 3600));
            self::assertIsString($row['puzzles']);
            $puzzles = json_decode($row['puzzles'], true);
            self::assertIsArray($puzzles);

            foreach ($puzzles as $item) {
                self::assertIsArray($item);
                $at = $item['atSeconds'] ?? 0;
                self::assertIsInt($at);
                self::assertLessThanOrEqual(3600, $at);
            }
        }
    }

    /**
     * The St. Paul Winter Carnival 2027 demo at its full made-up size: the website's schedule in Central time, three team
     * contests on one puzzle, divisions on Sunday's pairs and solo, plain table numbers per contest with the parallel
     * Sunday contests in two ranges, no standing (each contest on its own). Built on a day still ahead (the test clock is
     * weeks before DAY): OPEN #2 and Family Teams wait - no results, no stopwatch.
     */
    public function testBuildsTheCarnival2027DemoOnRequest(): void
    {
        self::bootKernel();
        $database = self::getContainer()->get(Connection::class);
        $this->createMadeUpPlayers(40);

        $simulationData = self::getContainer()->get(GetSandboxSimulationData::class);
        $organizer = $simulationData->playerIdByEmail('test-user+1@myspeedpuzzling.com');
        $referee = $simulationData->playerIdByEmail('test-user+2@myspeedpuzzling.com');
        $maintainer = PlayerFixture::PLAYER_REGULAR;
        self::assertNotNull($organizer);
        self::assertNotNull($referee);

        $lines = [];
        $this->service(true)->setUp(self::DAY, $organizer, $referee, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }, SandboxDemoEventDefinitions::CARNIVAL_2027_SLUG, [$maintainer]);

        self::assertSame([], array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'TODO:'))));
        $carnival = $this->competitionId(SandboxDemoEventDefinitions::CARNIVAL_2027_SLUG);
        self::assertSame(2, $this->rowCount('SELECT COUNT(*) FROM competition_maintainer WHERE competition_id = :id AND player_id IN (:a, :b)', ['id' => $carnival, 'a' => $organizer, 'b' => $maintainer]));
        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM organization o JOIN competition c ON c.organization_id = o.id WHERE c.id = :id AND o.approved_at IS NOT NULL', ['id' => $carnival]));

        // The website's schedule, America/Chicago (UTC-6 in winter)
        $rounds = $database->fetchAllAssociative('SELECT name, minutes_limit, starts_at, category FROM competition_round WHERE competition_id = :id ORDER BY starts_at, name', ['id' => $carnival]);
        self::assertSame([
            ['500 Teams CHALLENGE', 90, '2026-12-01 15:00:00', 'team'],
            ['1000 Teams', 180, '2026-12-01 17:15:00', 'team'],
            ['500 Teams OPEN #1', 120, '2026-12-01 21:00:00', 'team'],
            ['500 Pairs', 120, '2026-12-02 15:30:00', 'duo'],
            ['500 Solo', 120, '2026-12-02 18:15:00', 'solo'],
            ['500 Family Teams', 120, '2026-12-02 21:00:00', 'team'],
            ['500 Teams OPEN #2', 120, '2026-12-02 21:00:00', 'team'],
        ], array_map(static fn (array $round): array => [$round['name'], $round['minutes_limit'], $round['starts_at'], $round['category']], $rounds));

        // One puzzle for CHALLENGE, OPEN #1 and OPEN #2
        self::assertSame(1, $this->rowCount(<<<SQL
SELECT COUNT(DISTINCT crp.puzzle_id)
FROM competition_round_puzzle crp
JOIN competition_round cr ON cr.id = crp.round_id
WHERE cr.competition_id = :id AND cr.name IN ('500 Teams CHALLENGE', '500 Teams OPEN #1', '500 Teams OPEN #2')
SQL, ['id' => $carnival]));

        // Full-ish size: 920 people, the pairs and solo puzzlers are team people again
        self::assertSame(920, $this->rowCount('SELECT COUNT(*) FROM competition_participant WHERE competition_id = :id AND deleted_at IS NULL', ['id' => $carnival]));
        $teams = $database->fetchAllKeyValue('SELECT cr.name, COUNT(ct.id) FROM competition_round cr LEFT JOIN competition_team ct ON ct.round_id = cr.id WHERE cr.competition_id = :id AND cr.category <> \'solo\' GROUP BY cr.name', ['id' => $carnival]);
        self::assertEquals(['500 Teams CHALLENGE' => 40, '1000 Teams' => 80, '500 Teams OPEN #1' => 60, '500 Pairs' => 60, '500 Teams OPEN #2' => 60, '500 Family Teams' => 30], $teams);

        // Plain table numbers per contest; the CHALLENGE teams keep their table in the 1000; Family after OPEN #2
        $tables = $database->fetchAllAssociative('SELECT cr.name, MIN(ct.table_number) AS first, MAX(ct.table_number) AS last, COUNT(DISTINCT ct.table_number) AS tables FROM competition_round cr JOIN competition_team ct ON ct.round_id = cr.id WHERE cr.competition_id = :id GROUP BY cr.name ORDER BY cr.name', ['id' => $carnival]);
        self::assertSame([
            ['1000 Teams', 1, 80, 80],
            ['500 Family Teams', 61, 90, 30],
            ['500 Pairs', 1, 60, 60],
            ['500 Teams CHALLENGE', 1, 40, 40],
            ['500 Teams OPEN #1', 1, 60, 60],
            ['500 Teams OPEN #2', 1, 60, 60],
        ], array_map(static fn (array $row): array => [$row['name'], $row['first'], $row['last'], $row['tables']], $tables));
        $challenge = $this->round($carnival, '500 Teams CHALLENGE');
        self::assertSame(40, $this->rowCount('SELECT COUNT(*) FROM competition_team a JOIN competition_team b ON b.round_id = :next AND b.name = a.name AND b.table_number = a.table_number WHERE a.round_id = :first', ['first' => $challenge['id'], 'next' => $this->round($carnival, '1000 Teams')['id']]));
        self::assertTrue(self::rangeIs(1, 120, $database->fetchFirstColumn('SELECT table_number FROM competition_participant_round WHERE round_id = :id ORDER BY table_number', ['id' => $this->round($carnival, '500 Solo')['id']])));

        // Divisions: Regular / Senior 55+ on the pairs, + Junior 15 and under on the solo
        $solo = $this->round($carnival, '500 Solo');
        self::assertEquals(['Junior 15 and under' => 20, 'Regular' => 80, 'Senior 55+' => 20], $database->fetchAllKeyValue('SELECT d.name, COUNT(*) FROM competition_participant_round cpr JOIN competition_division d ON d.id = cpr.division_id WHERE cpr.round_id = :id GROUP BY d.name ORDER BY d.name', ['id' => $solo['id']]));
        self::assertEquals(['Regular' => 45, 'Senior 55+' => 15], $database->fetchAllKeyValue('SELECT d.name, COUNT(*) FROM competition_team ct JOIN competition_division d ON d.id = ct.division_id WHERE ct.round_id = :id GROUP BY d.name ORDER BY d.name', ['id' => $this->round($carnival, '500 Pairs')['id']]));

        // Saturday and Sunday morning published with everybody's result, a tie, time's up with pieces placed
        foreach (['500 Teams CHALLENGE', '1000 Teams', '500 Teams OPEN #1', '500 Pairs'] as $name) {
            $round = $this->round($carnival, $name);
            self::assertNotNull($round['results_published_at'], $name);
            self::assertSame($name === '1000 Teams' ? 80 : ($name === '500 Teams CHALLENGE' ? 40 : 60), $this->teamResults($round['id']), $name);
            self::assertGreaterThanOrEqual(1, $this->rowCount('SELECT COUNT(*) FROM (SELECT result_seconds FROM competition_team WHERE round_id = :id AND result_seconds IS NOT NULL GROUP BY result_seconds HAVING COUNT(*) >= 2) t', ['id' => $round['id']]), $name);
            self::assertGreaterThanOrEqual(1, $this->rowCount('SELECT COUNT(*) FROM competition_team WHERE round_id = :id AND result_pieces_placed IS NOT NULL', ['id' => $round['id']]), $name);
        }
        self::assertNotNull($solo['results_published_at']);
        self::assertSame(120, $this->rowCount('SELECT COUNT(*) FROM competition_participant_round WHERE round_id = :id AND (result_seconds IS NOT NULL OR result_pieces_placed IS NOT NULL OR result_did_not_start)', ['id' => $solo['id']]));

        // The demo day is ahead: OPEN #2 and Family Teams wait for the organisers' own start
        foreach (['500 Teams OPEN #2', '500 Family Teams'] as $name) {
            self::assertSame(0, $this->teamResults($this->round($carnival, $name)['id']), $name);
        }
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM competition_round WHERE competition_id = :id AND stopwatch_status IS NOT NULL', ['id' => $carnival]));

        // Each contest on its own: no standing; winter hall screens, a stream overlay and the divisions board
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM competition_standing WHERE competition_id = :id', ['id' => $carnival]));
        self::assertSame(
            ['Divisions board', 'Hall screen', 'Hall screen - Family Teams', 'Stream overlay'],
            $database->fetchFirstColumn('SELECT name FROM competition_display WHERE competition_id = :id ORDER BY name', ['id' => $carnival]),
        );
        // The main hall screen follows every round but Family Teams (which has its own screen) - OPEN #2 at 15:00
        $follows = $database->fetchOne("SELECT follow_round_ids::text FROM competition_display WHERE competition_id = :id AND name = 'Hall screen'", ['id' => $carnival]);
        self::assertIsString($follows);
        $followed = json_decode($follows, true);
        self::assertIsArray($followed);
        self::assertCount(6, $followed);
        self::assertNotContains($this->round($carnival, '500 Family Teams')['id'], $followed);
        self::assertContains($this->round($carnival, '500 Teams OPEN #2')['id'], $followed);

        // Again: nothing new, nothing changed
        $snapshot = $this->snapshot([SandboxDemoEventDefinitions::CARNIVAL_2027_SLUG]);
        $this->service(true)->setUp(self::DAY, $organizer, $referee, static function (string $line): void {
        }, SandboxDemoEventDefinitions::CARNIVAL_2027_SLUG, [$maintainer]);
        self::assertSame($snapshot, $this->snapshot([SandboxDemoEventDefinitions::CARNIVAL_2027_SLUG]));
    }

    /**
     * @param list<mixed> $numbers
     */
    private static function rangeIs(int $first, int $last, array $numbers): bool
    {
        return array_map(static fn (mixed $number): int => is_numeric($number) ? (int) $number : -1, $numbers) === range($first, $last);
    }

    public function testRefusedOutsideTheSandbox(): void
    {
        self::bootKernel();

        try {
            $this->service(false)->setUp(self::DAY, 'unused', null, static function (string $line): void {
            });
            self::fail('The demo events were built outside the sandbox');
        } catch (SandboxModeIsOff) {
        }

        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM competition WHERE slug IN (:a, :b)', ['a' => SandboxDemoEventDefinitions::SPWS_SLUG, 'b' => SandboxDemoEventDefinitions::CARNIVAL_SLUG]));
    }

    private function service(bool $sandbox): SandboxDemoEvents
    {
        $container = self::getContainer();

        // The sandbox-only handlers it dispatches to (StartSandboxDemoRoundStopwatch) see the sandbox too
        if ($sandbox && $container->initialized(SandboxMode::class) === false) {
            $container->set(SandboxMode::class, new SandboxMode(true));
        }

        return new SandboxDemoEvents(
            new SandboxMode($sandbox),
            $container->get(MessageBusInterface::class),
            $container->get(GetSandboxDemoData::class),
            $container->get(GetSandboxSimulationData::class),
            $container->get(GetRoundResultsOverview::class),
            $container->get(GetRoundResultEntries::class),
            $container->get(GetCompetitionDivisions::class),
            $container->get(SameTablesCopy::class),
            $container->get(EntityManagerInterface::class),
            $container->get(ClockInterface::class),
            $container->get(UrlGeneratorInterface::class),
            $container->get(TranslatorInterface::class),
        );
    }

    private function createMadeUpPlayers(int $count): void
    {
        $container = self::getContainer();
        $handler = new CreateSandboxPlayersHandler(
            new SandboxMode(true),
            $container->get(UserAccountRepository::class),
            $container->get(PlayerRepository::class),
            $container->get(MembershipRepository::class),
            $container->get(UserPasswordHasherInterface::class),
            $container->get(GenerateUniquePlayerCode::class),
            $container->get(ClockInterface::class),
        );
        $handler(new CreateSandboxPlayers($count, 'sandbox'));
        $container->get(EntityManagerInterface::class)->flush();
    }

    private function competitionId(string $slug): string
    {
        $id = self::getContainer()->get(Connection::class)->fetchOne('SELECT id FROM competition WHERE slug = :slug', ['slug' => $slug]);
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function round(string $competitionId, string $name): array
    {
        $row = self::getContainer()->get(Connection::class)->fetchAssociative(<<<SQL
SELECT id, puzzle_format, puzzles_to_solve, category, minutes_limit, unboxed, starts_at, results_published_at
FROM competition_round
WHERE competition_id = :id AND name = :name
SQL, ['id' => $competitionId, 'name' => $name]);
        self::assertIsArray($row);

        return $row;
    }

    private function teamResults(mixed $roundId): int
    {
        return $this->rowCount('SELECT COUNT(*) FROM competition_team WHERE round_id = :id AND (result_seconds IS NOT NULL OR result_pieces_placed IS NOT NULL OR result_did_not_start)', ['id' => $roundId]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function rowCount(string $sql, array $params): int
    {
        $count = self::getContainer()->get(Connection::class)->fetchOne($sql, $params);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * Everything the demo writes, counted - and every recorded result as it stands
     *
     * @param list<string> $slugs
     * @return array<string, mixed>
     */
    private function snapshot(array $slugs = [SandboxDemoEventDefinitions::SPWS_SLUG, SandboxDemoEventDefinitions::CARNIVAL_SLUG]): array
    {
        $database = self::getContainer()->get(Connection::class);
        $events = ['slugs' => '{' . implode(',', $slugs) . '}'];
        $ofEvents = 'SELECT id FROM competition WHERE slug = ANY(CAST(:slugs AS text[]))';
        $rounds = "SELECT id FROM competition_round WHERE competition_id IN ({$ofEvents})";

        return [
            'competitions' => $this->rowCount("SELECT COUNT(*) FROM ({$ofEvents}) c", $events),
            'rounds' => $this->rowCount("SELECT COUNT(*) FROM ({$rounds}) r", $events),
            'round puzzles' => $this->rowCount("SELECT COUNT(*) FROM competition_round_puzzle WHERE round_id IN ({$rounds})", $events),
            'participants' => $this->rowCount("SELECT COUNT(*) FROM competition_participant WHERE competition_id IN ({$ofEvents})", $events),
            'entries' => $this->rowCount("SELECT COUNT(*) FROM competition_participant_round WHERE round_id IN ({$rounds})", $events),
            'teams' => $database->fetchAllAssociative("SELECT id, table_number, result_seconds, result_pieces_placed, result_did_not_start, result_missing_pieces, result_puzzles::text AS puzzles, division_id FROM competition_team WHERE round_id IN ({$rounds}) ORDER BY id", $events),
            'people' => $database->fetchAllAssociative("SELECT id, table_number, result_seconds, result_pieces_placed, result_did_not_start, division_id FROM competition_participant_round WHERE round_id IN ({$rounds}) ORDER BY id", $events),
            'standings' => $this->rowCount("SELECT COUNT(*) FROM competition_standing WHERE competition_id IN ({$ofEvents})", $events),
            'displays' => $this->rowCount("SELECT COUNT(*) FROM competition_display WHERE competition_id IN ({$ofEvents})", $events),
            'divisions' => $this->rowCount("SELECT COUNT(*) FROM competition_division WHERE competition_id IN ({$ofEvents})", $events),
            'designs' => $database->fetchFirstColumn("SELECT design::text FROM competition_display WHERE competition_id IN ({$ofEvents}) ORDER BY id", $events),
            'reveals' => $database->fetchAllAssociative("SELECT id, label, position, unboxed, reveal_mode, hide_until_round_starts FROM competition_round_puzzle WHERE round_id IN ({$rounds}) ORDER BY id", $events),
            'table names' => $database->fetchFirstColumn("SELECT COALESCE(table_labels::text, '') FROM competition_round WHERE id IN ({$rounds}) ORDER BY id", $events),
            'organizations' => $this->rowCount('SELECT COUNT(*) FROM organization', []),
            'organization teams' => $this->rowCount('SELECT COUNT(*) FROM organization_maintainer', []),
        ];
    }
}
