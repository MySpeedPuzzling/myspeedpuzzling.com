<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\Insights\InsightsGeneration;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\ChangedRowsSync;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\DerivedMetricsCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\ImprovementRatioCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PredictionModelFitter;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingFormula;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceHistoryReadiness;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PaceTimelineLoader;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerBaselineCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerPaceHistoryCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerPaceHistoryWriter;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerSkillCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelParametersProvider;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelRecalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleDifficultyCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\RatingInsightsWriter;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Tests\InsightsLadderSeeding;
use SpeedPuzzling\Web\Value\RatingPortfolio;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * What the insights batch stores of the MSP rating (docs/features/insights/computation.md): the entries are exactly
 * what MspRatingCalculator computes in memory and add up to the stored rating, the time arrays are sorted and leave out
 * suspicious times, private players and secret puzzles, only changed rows are written, and every run moves the
 * generation on.
 */
final class RatingInsightsBatchTest extends KernelTestCase
{
    use InsightsLadderSeeding;

    private const array TABLES = [
        'player_rating_entry' => ['player_id', 'pieces_count', 'puzzle_id', 'portfolio'],
        'puzzle_rating_times' => ['puzzle_id'],
    ];

    private Connection $connection;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $this->connection = $connection;
        $this->now = new DateTimeImmutable('today 12:00:00');

        $this->seedLadder($this->connection, $this->now);
    }

    public function testStoredEntriesAreWhatTheCalculatorComputesAndAddUpToTheRating(): void
    {
        $this->recalculate($this->now);

        $calculator = $this->preloadedCalculator($this->now);
        /** @var array<string, float|string> $ratings */
        $ratings = $this->connection->fetchAllKeyValue('SELECT player_id, elo_rating FROM player_elo WHERE pieces_count = 500');
        self::assertCount(22, $ratings, 'Every public ladder player is rated');

        foreach (range(1, 22) as $number) {
            $playerId = self::ladderPlayer($number);
            $portfolio = $calculator->calculatePortfolio($playerId, 500);
            $stored = $this->storedEntries($playerId);

            self::assertCount(count($portfolio->entries), $stored);

            foreach ($portfolio->entries as $entry) {
                $row = $stored[$entry->puzzleId . '|' . $entry->portfolio->value] ?? null;
                self::assertNotNull($row, "{$entry->puzzleId} {$entry->portfolio->value}");
                self::assertSame($entry->seconds, (int) $row['seconds']);
                self::assertSame((new DateTimeImmutable($entry->solvedAt))->format('Y-m-d H:i:s'), (new DateTimeImmutable($row['solved_at']))->format('Y-m-d H:i:s'));
                self::assertSame($entry->percentile, (float) $row['percentile']);
                self::assertSame($entry->difficultyWeight, (float) $row['difficulty_weight']);
                self::assertSame($entry->decay, (float) $row['decay']);
                self::assertSame($entry->points, (float) $row['points']);
                self::assertSame($entry->inTop, (bool) $row['in_top']);
            }

            // The counted entries reproduce the stored rating
            $top = [RatingPortfolio::FirstAttempt->value => [], RatingPortfolio::BestTime->value => []];

            foreach ($stored as $row) {
                if ((bool) $row['in_top']) {
                    $top[$row['portfolio']][] = (float) $row['points'];
                }
            }

            rsort($top[RatingPortfolio::FirstAttempt->value]);
            rsort($top[RatingPortfolio::BestTime->value]);

            self::assertEqualsWithDelta(
                (float) $ratings[$playerId],
                MspRatingFormula::rating($top[RatingPortfolio::FirstAttempt->value], $top[RatingPortfolio::BestTime->value]),
                1e-12,
                "Player {$number}",
            );
            self::assertGreaterThan(0.0, $portfolio->rating);
            self::assertEqualsWithDelta($portfolio->rating, (float) $ratings[$playerId], 1e-12);
        }

        // Private players have no rating and no entries
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM player_rating_entry WHERE player_id IN (:players)', ['players' => [self::ladderPlayer(23), self::ladderPlayer(24)]]));
    }

    /**
     * The public ratings must not move with the refactor: the stored player_elo and the new calculator give the very
     * same floats as the calculator before 4a (LegacyMspRatingCalculator, a verbatim copy) - on a ladder with portfolios
     * over the 100-entry cap, a nearly rated puzzle (now loaded, never counted), a secret puzzle, a suspicious time and
     * private players.
     */
    public function testPublicRatingsAreExactlyThoseOfTheAlgorithmBefore4a(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO puzzle (id, pieces_count, name, approved, is_available, hide_until)
            SELECT CAST('019b0001-0000-7000-8000-' || lpad(n::text, 12, '0') AS uuid), 500, 'Insights Puzzle ' || n, true, true,
                CASE WHEN n = 103 THEN CAST(:future AS timestamp) END
            FROM generate_series(56, 103) AS n",
            ['future' => $this->now->modify('+1 year')->format('Y-m-d H:i:s')],
        );
        $this->seedSolves($this->connection, $this->now, [
            // Players 1-15 pass the 100-entry cap
            'SELECT p, z, 1500 + 60 * p + 23 * z + ((p * 37 + z * 11) % 50) * 9, 100 + (p * 7 + z * 13) % 500, true FROM generate_series(1, 15) AS p CROSS JOIN generate_series(56, 101) AS z',
            // Puzzle 102: 10 solvers - nearly rated; puzzle 103: everybody, but secret
            'SELECT p, 102, 2000 + 10 * p, 50, true FROM generate_series(1, 10) AS p',
            'SELECT p, 103, 2100 + 7 * p, 40, true FROM generate_series(1, 24) AS p',
            // Repeats on a few of the new puzzles
            'SELECT p, z, 1400 + 50 * p, 20, false FROM generate_series(1, 6) AS p CROSS JOIN generate_series(60, 64) AS z',
        ]);
        $this->connection->executeStatement(
            'UPDATE puzzle_solving_time SET suspicious = true WHERE player_id = :player AND puzzle_id = :puzzle',
            ['player' => self::ladderPlayer(4), 'puzzle' => self::ladderPuzzle(2)],
        );

        $this->recalculate($this->now);

        $clock = new MockClock($this->now);
        $legacy = new LegacyMspRatingCalculator($this->connection, $clock);
        $legacy->precomputePuzzleRankings(500);
        $legacy->preloadAllPlayerSolves(500);
        $current = $this->preloadedCalculator($this->now);

        /** @var array<string, float|string> $stored */
        $stored = $this->connection->fetchAllKeyValue('SELECT player_id, elo_rating FROM player_elo WHERE pieces_count = 500');
        self::assertCount(22, $stored);

        /** @var list<string> $players */
        $players = $this->connection->fetchFirstColumn('SELECT DISTINCT pst.player_id FROM puzzle_solving_time pst JOIN player p ON p.id = pst.player_id WHERE pst.seconds_to_solve IS NOT NULL AND p.is_private = false');

        foreach ($players as $playerId) {
            $expected = $legacy->calculateForPlayer($playerId, 500);

            self::assertSame($expected, $current->calculateForPlayer($playerId, 500), $playerId);
            // player_elo is written as before (writeChangedRows binds the float as PHP prints it, ~14 digits): the
            // legacy value through the same binding is the stored one
            /** @var float|string $legacyStored */
            $legacyStored = $this->connection->fetchOne('SELECT CAST(:rating AS double precision)', ['rating' => $expected]);
            self::assertSame((float) $legacyStored, isset($stored[$playerId]) ? (float) $stored[$playerId] : 0.0, $playerId);
        }

        // The per-player path (no preload, --player runs) too
        $legacyOnePlayer = new LegacyMspRatingCalculator($this->connection, $clock);
        $legacyOnePlayer->precomputePuzzleRankings(500);
        $currentOnePlayer = new MspRatingCalculator($this->connection, $clock);
        $currentOnePlayer->precomputePuzzleRankings(500);

        foreach ([1, 4, 15, 16, 22] as $number) {
            self::assertSame(
                $legacyOnePlayer->calculateForPlayer(self::ladderPlayer($number), 500),
                $currentOnePlayer->calculateForPlayer(self::ladderPlayer($number), 500),
            );
        }
    }

    public function testTheTop100IsMarked(): void
    {
        // 46 more puzzles for player 1: 101 first attempts, so one is left out of the counted 100
        $this->connection->executeStatement(
            "INSERT INTO puzzle (id, pieces_count, name, approved, is_available)
            SELECT CAST('019b0001-0000-7000-8000-' || lpad(n::text, 12, '0') AS uuid), 500, 'Insights Puzzle ' || n, true, true
            FROM generate_series(56, 101) AS n",
        );
        $this->seedSolves($this->connection, $this->now, [
            'SELECT p, z, 1500 + 60 * p + 23 * z + ((p * 37 + z * 11) % 50) * 9, 100 + (p * 7 + z * 13) % 500, true FROM generate_series(1, 22) AS p CROSS JOIN generate_series(56, 101) AS z',
        ]);

        $this->recalculate($this->now);

        /** @var array{entries: int|string, counted: int|string, lowest_counted: float|string, highest_left_out: float|string}|false $counts */
        $counts = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS entries, COUNT(*) FILTER (WHERE in_top) AS counted, MIN(points) FILTER (WHERE in_top) AS lowest_counted, MAX(points) FILTER (WHERE NOT in_top) AS highest_left_out
            FROM player_rating_entry WHERE player_id = :player AND portfolio = 'first_attempt'",
            ['player' => self::ladderPlayer(1)],
        );
        self::assertIsArray($counts);
        self::assertSame(101, (int) $counts['entries']);
        self::assertSame(MspRatingFormula::CAP, (int) $counts['counted']);
        self::assertGreaterThanOrEqual((float) $counts['highest_left_out'], (float) $counts['lowest_counted']);
    }

    public function testTimeArraysAreSortedAndLeaveOutSuspiciousPrivateAndSecret(): void
    {
        // Puzzle 60: 10 public first-attempt solvers - not rated yet, but near; puzzle 61: 5 - nothing stored;
        // puzzle 62: everybody, but secret
        $this->connection->executeStatement(
            "INSERT INTO puzzle (id, pieces_count, name, approved, is_available, hide_until)
            VALUES (:p60, 500, 'Near', true, true, NULL), (:p61, 500, 'Far', true, true, NULL), (:p62, 500, 'Secret', true, true, :future)",
            ['p60' => self::ladderPuzzle(60), 'p61' => self::ladderPuzzle(61), 'p62' => self::ladderPuzzle(62), 'future' => $this->now->modify('+1 year')->format('Y-m-d H:i:s')],
        );
        $this->seedSolves($this->connection, $this->now, [
            'SELECT p, 60, 2000 + 10 * p, 50, true FROM generate_series(1, 10) AS p',
            'SELECT p, 61, 2000 + 10 * p, 50, true FROM generate_series(1, 5) AS p',
            'SELECT p, 62, 2000 + 10 * p, 50, true FROM generate_series(1, 24) AS p',
        ]);

        // Player 2's first attempt on puzzle 1 needs verification: in no array
        /** @var int|string $suspiciousSeconds */
        $suspiciousSeconds = $this->connection->fetchOne(
            'SELECT seconds_to_solve FROM puzzle_solving_time WHERE player_id = :player AND puzzle_id = :puzzle AND first_attempt = true',
            ['player' => self::ladderPlayer(2), 'puzzle' => self::ladderPuzzle(1)],
        );
        $this->connection->executeStatement(
            'UPDATE puzzle_solving_time SET suspicious = true WHERE player_id = :player AND puzzle_id = :puzzle',
            ['player' => self::ladderPlayer(2), 'puzzle' => self::ladderPuzzle(1)],
        );

        $this->recalculate($this->now);

        $rows = $this->timeArrays();

        self::assertArrayHasKey(self::ladderPuzzle(60), $rows);
        self::assertArrayNotHasKey(self::ladderPuzzle(61), $rows);
        self::assertArrayNotHasKey(self::ladderPuzzle(62), $rows, 'A secret puzzle never has stored times');
        self::assertCount(55 + 1, $rows);

        $near = $rows[self::ladderPuzzle(60)];
        self::assertFalse($near['qualifies']);
        self::assertSame(10, $near['solver_count']);
        self::assertSame(array_map(static fn (int $p): int => 2000 + 10 * $p, range(1, 10)), $near['first_attempt_times']);

        foreach (range(1, 55) as $number) {
            $row = $rows[self::ladderPuzzle($number)];
            self::assertTrue($row['qualifies']);
            self::assertNotNull($row['difficulty_weight']);

            foreach (['first_attempt_times', 'best_times'] as $column) {
                $sorted = $row[$column];
                sort($sorted);
                self::assertSame($sorted, $row[$column], "{$column} sorted");
            }

            // 22 public players; private 23 and 24 are left out; player 2's suspicious time on puzzle 1 too
            $expected = $number === 1 ? 21 : 22;
            self::assertSame($expected, $row['solver_count']);
            self::assertCount($expected, $row['first_attempt_times']);
            self::assertCount($expected, $row['best_times']);
        }

        $puzzle1 = $rows[self::ladderPuzzle(1)];
        /** @var list<int|string> $privateSecondsRaw */
        $privateSecondsRaw = $this->connection->fetchFirstColumn(
            'SELECT seconds_to_solve FROM puzzle_solving_time WHERE puzzle_id = :puzzle AND player_id IN (:players)',
            ['puzzle' => self::ladderPuzzle(1), 'players' => [self::ladderPlayer(23), self::ladderPlayer(24)]],
            ['players' => ArrayParameterType::STRING],
        );
        $privateSeconds = array_map(static fn (int|string $seconds): int => (int) $seconds, $privateSecondsRaw);

        foreach ([(int) $suspiciousSeconds, ...$privateSeconds] as $leftOut) {
            self::assertNotContains($leftOut, $puzzle1['first_attempt_times']);
        }

        // The best time of a player with repeats is their fastest attempt
        /** @var int|string $player1Best */
        $player1Best = $this->connection->fetchOne(
            'SELECT MIN(seconds_to_solve) FROM puzzle_solving_time WHERE player_id = :player AND puzzle_id = :puzzle',
            ['player' => self::ladderPlayer(1), 'puzzle' => self::ladderPuzzle(1)],
        );
        self::assertContains((int) $player1Best, $puzzle1['best_times']);

        // A rating what-if over the stored array gives the stored percentile back
        /** @var array{seconds: int|string, percentile: float|string}|false $entry */
        $entry = $this->connection->fetchAssociative(
            "SELECT seconds, percentile FROM player_rating_entry WHERE player_id = :player AND puzzle_id = :puzzle AND portfolio = 'first_attempt'",
            ['player' => self::ladderPlayer(5), 'puzzle' => self::ladderPuzzle(3)],
        );
        self::assertIsArray($entry);
        self::assertSame((float) $entry['percentile'], MspRatingFormula::percentileInSortedTimes((int) $entry['seconds'], $rows[self::ladderPuzzle(3)]['first_attempt_times']));
    }

    public function testASecondRunOverUnchangedDataWritesNothing(): void
    {
        $this->recalculate($this->now);
        $before = $this->contents();
        $writesBefore = $this->writes();

        $this->recalculate($this->now);

        self::assertSame($before, $this->contents());
        self::assertSame($writesBefore, $this->writes(), 'A run over unchanged data writes no row');
    }

    public function testAChangedSolveRewritesExactlyTheRowsWhoseValuesChange(): void
    {
        $this->recalculate($this->now);
        $before = $this->contents();

        $this->connection->executeStatement(
            'UPDATE puzzle_solving_time SET seconds_to_solve = 9000 WHERE player_id = :player AND puzzle_id = :puzzle',
            ['player' => self::ladderPlayer(3), 'puzzle' => self::ladderPuzzle(7)],
        );

        $this->recalculate($this->now);
        $after = $this->contents();
        $this->assertOnlyChangedRowsWritten($before, $after);

        foreach (array_keys(self::TABLES) as $table) {
            self::assertLessThan(count($before[$table]), count(self::changedRows($before[$table], $after[$table])), "{$table}: not everything changed");
        }

        // A player turning private takes their entries and their times with them
        $this->connection->executeStatement('UPDATE player SET is_private = true WHERE id = :player', ['player' => self::ladderPlayer(22)]);

        $this->recalculate($this->now);
        $this->assertOnlyChangedRowsWritten($after, $this->contents());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM player_rating_entry WHERE player_id = :player', ['player' => self::ladderPlayer(22)]));
        self::assertSame(21, $this->countRows("SELECT jsonb_array_length(first_attempt_times) FROM puzzle_rating_times WHERE puzzle_id = :puzzle", ['puzzle' => self::ladderPuzzle(9)]));
    }

    /**
     * @param array<string, array<string, array{row_values: string, ctid: string}>> $before
     * @param array<string, array<string, array{row_values: string, ctid: string}>> $after
     */
    private function assertOnlyChangedRowsWritten(array $before, array $after): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            $changed = self::changedRows($before[$table], $after[$table]);
            $written = [];

            foreach (array_keys($before[$table] + $after[$table]) as $rowKey) {
                $old = $before[$table][$rowKey] ?? null;
                $new = $after[$table][$rowKey] ?? null;

                if ($old === null || $new === null || $old['ctid'] !== $new['ctid']) {
                    $written[] = $rowKey;
                }
            }

            self::assertNotSame([], $changed, "{$table}: the change reaches it");
            self::assertSame($changed, $written, "{$table}: exactly the rows whose values changed are written");
        }
    }

    /**
     * @param array<string, array{row_values: string, ctid: string}> $before
     * @param array<string, array{row_values: string, ctid: string}> $after
     * @return list<string>
     */
    private static function changedRows(array $before, array $after): array
    {
        $changed = [];

        foreach (array_keys($before + $after) as $rowKey) {
            $old = $before[$rowKey] ?? null;
            $new = $after[$rowKey] ?? null;

            if ($old === null || $new === null || $old['row_values'] !== $new['row_values']) {
                $changed[] = (string) $rowKey;
            }
        }

        return $changed;
    }

    public function testEveryRunMovesTheGenerationOn(): void
    {
        $generation = new InsightsGeneration($this->connection, new MockClock($this->now));
        $before = $generation->current();

        $this->recalculate($this->now);
        self::assertSame($before + 1, $generation->current());

        $this->recalculate($this->now);
        self::assertSame($before + 2, $generation->current());
    }

    public function testASinglePlayerRunKeepsEverybodyElsesEntries(): void
    {
        $this->recalculate($this->now);
        $before = $this->contents()['player_rating_entry'];

        $this->recalculator($this->now)->recalculate(self::ladderPlayer(4));

        self::assertSame($before, $this->contents()['player_rating_entry']);
    }

    private function recalculate(DateTimeImmutable $now): void
    {
        $this->recalculator($now)->recalculate();
    }

    private function recalculator(DateTimeImmutable $now): PuzzleIntelligenceRecalculator
    {
        $clock = new MockClock($now);
        $baselineCalculator = new PlayerBaselineCalculator($this->connection, $clock);
        $paceHistoryReadiness = new PaceHistoryReadiness($this->connection);
        $paceTimelineLoader = new PaceTimelineLoader($this->connection, $baselineCalculator);

        $paceHistoryWriter = new PlayerPaceHistoryWriter($this->connection, $baselineCalculator, new PlayerPaceHistoryCalculator($baselineCalculator), $paceHistoryReadiness);

        return new PuzzleIntelligenceRecalculator(
            $this->connection,
            $clock,
            $baselineCalculator,
            $paceHistoryWriter,
            $paceHistoryReadiness,
            new PuzzleDifficultyCalculator($baselineCalculator, $paceTimelineLoader),
            new PlayerSkillCalculator($this->connection, $clock),
            new DerivedMetricsCalculator($this->connection, $paceTimelineLoader, $paceHistoryReadiness),
            new MspRatingCalculator($this->connection, $clock),
            new ImprovementRatioCalculator($this->connection),
            $paceTimelineLoader,
            new PredictionModelRecalculator(
                $this->connection,
                new TimePredictionCalculator(),
                new PredictionModelFitter(),
                new PredictionModelParametersProvider($this->connection),
                $paceTimelineLoader,
                $baselineCalculator,
                $paceHistoryWriter,
            ),
            new RatingInsightsWriter($this->connection, new ChangedRowsSync($this->connection)),
            new InsightsGeneration($this->connection, $clock),
        );
    }

    private function preloadedCalculator(DateTimeImmutable $now): MspRatingCalculator
    {
        $calculator = new MspRatingCalculator($this->connection, new MockClock($now));
        $calculator->precomputePuzzleRankings(500);
        $calculator->preloadAllPlayerSolves(500);

        return $calculator;
    }

    /**
     * @return array<string, array{puzzle_id: string, portfolio: string, seconds: int|string, solved_at: string, percentile: float|string, difficulty_weight: float|string, decay: float|string, points: float|string, in_top: bool|int|string}>
     */
    private function storedEntries(string $playerId): array
    {
        /** @var list<array{puzzle_id: string, portfolio: string, seconds: int|string, solved_at: string, percentile: float|string, difficulty_weight: float|string, decay: float|string, points: float|string, in_top: bool|int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT puzzle_id, portfolio, seconds, solved_at, percentile, difficulty_weight, decay, points, in_top FROM player_rating_entry WHERE player_id = :player AND pieces_count = 500',
            ['player' => $playerId],
        );

        $entries = [];

        foreach ($rows as $row) {
            $entries[$row['puzzle_id'] . '|' . $row['portfolio']] = $row;
        }

        return $entries;
    }

    /**
     * @return array<string, array{qualifies: bool, solver_count: int, difficulty_weight: null|float, first_attempt_times: list<int>, best_times: list<int>}>
     */
    private function timeArrays(): array
    {
        /** @var list<array{puzzle_id: string, qualifies: bool|int|string, solver_count: int|string, difficulty_weight: null|float|string, first_attempt_times: string, best_times: string}> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT puzzle_id, qualifies, solver_count, difficulty_weight, first_attempt_times, best_times FROM puzzle_rating_times');
        $arrays = [];

        foreach ($rows as $row) {
            /** @var list<int> $firstAttemptTimes */
            $firstAttemptTimes = json_decode($row['first_attempt_times'], true, flags: JSON_THROW_ON_ERROR);
            /** @var list<int> $bestTimes */
            $bestTimes = json_decode($row['best_times'], true, flags: JSON_THROW_ON_ERROR);

            $arrays[$row['puzzle_id']] = [
                'qualifies' => (bool) $row['qualifies'],
                'solver_count' => (int) $row['solver_count'],
                'difficulty_weight' => $row['difficulty_weight'] !== null ? (float) $row['difficulty_weight'] : null,
                'first_attempt_times' => $firstAttemptTimes,
                'best_times' => $bestTimes,
            ];
        }

        return $arrays;
    }

    /**
     * Every row by its key: its values (all columns but computed_at) and its row version (ctid)
     *
     * @return array<string, array<string, array{row_values: string, ctid: string}>>
     */
    private function contents(): array
    {
        $contents = [];

        foreach (self::TABLES as $table => $key) {
            $rowKey = implode(", '|', ", array_map(static fn (string $column): string => "t.{$column}::text", $key));

            /** @var list<array{row_key: string, row_values: string, ctid: string}> $rows */
            $rows = $this->connection->fetchAllAssociative("
                SELECT concat({$rowKey}) AS row_key, (to_jsonb(t) - 'computed_at')::text AS row_values, t.ctid::text AS ctid
                FROM {$table} t
                ORDER BY 1
            ");

            $contents[$table] = [];

            foreach ($rows as $row) {
                $contents[$table][$row['row_key']] = ['row_values' => $row['row_values'], 'ctid' => $row['ctid']];
            }
        }

        return $contents;
    }

    /**
     * @return array<string, int>
     */
    private function writes(): array
    {
        /** @var array<string, int|string> $writes */
        $writes = $this->connection->fetchAllKeyValue(
            'SELECT relname, n_tup_ins + n_tup_upd + n_tup_del FROM pg_stat_xact_user_tables WHERE relname IN (:tables) ORDER BY relname',
            ['tables' => array_keys(self::TABLES)],
            ['tables' => ArrayParameterType::STRING],
        );

        return array_map(static fn (int|string $count): int => (int) $count, $writes);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function countRows(string $sql, array $params = []): int
    {
        $types = [];

        foreach ($params as $name => $value) {
            if (is_array($value)) {
                $types[$name] = ArrayParameterType::STRING;
            }
        }

        /** @var int|string $count */
        $count = $this->connection->fetchOne($sql, $params, $types);

        return (int) $count;
    }
}
