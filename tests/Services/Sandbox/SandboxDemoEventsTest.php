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
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Results\CompetitionDivisionOverview;
use SpeedPuzzling\Web\Services\GenerateUniquePlayerCode;
use SpeedPuzzling\Web\Services\SameTablesCopy;
use SpeedPuzzling\Web\Services\Sandbox\SandboxDemoEventDefinitions;
use SpeedPuzzling\Web\Services\Sandbox\SandboxDemoEvents;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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

        // Again: nothing new, nothing changed
        $snapshot = $this->snapshot();
        $this->service(true)->setUp(self::DAY, $organizer, $referee, static function (string $line): void {
        });
        self::assertSame($snapshot, $this->snapshot());
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

        return new SandboxDemoEvents(
            new SandboxMode($sandbox),
            $container->get(MessageBusInterface::class),
            $container->get(GetSandboxDemoData::class),
            $container->get(GetSandboxSimulationData::class),
            $container->get(GetRoundResultsOverview::class),
            $container->get(GetRoundResultEntries::class),
            $container->get(GetCompetitionDivisions::class),
            $container->get(SameTablesCopy::class),
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
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $database = self::getContainer()->get(Connection::class);
        $events = ['a' => SandboxDemoEventDefinitions::SPWS_SLUG, 'b' => SandboxDemoEventDefinitions::CARNIVAL_SLUG];
        $ofEvents = 'SELECT id FROM competition WHERE slug IN (:a, :b)';
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
        ];
    }
}
