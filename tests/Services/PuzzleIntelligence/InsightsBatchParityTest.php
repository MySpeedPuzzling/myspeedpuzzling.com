<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The 15-minute batch writes exactly what it wrote before its memory was cut (2026-10-27, docs/features/
 * puzzle-intelligence/README.md "Memory of the 15-minute batch"): every table of every step - baselines, improvement
 * ratios, difficulty with the derived metrics, skills, ratings with their entries and puzzle times, skill history and
 * rating snapshots - compared to the bit with what the code before the change wrote on the same synthetic data
 * (InsightsBatchParity.json, recorded by that code). The sibling of ShownModelParityTest, which covers the prediction
 * model's fit; this one has puzzles with many solvers, so every derived metric, skill and rating is computed, not null.
 *
 * The data: 126 players (some private, some opted out of rankings) and 170 puzzles of five sizes, ~11k first tries
 * over 27 months (some flagged first attempts, some only the eldest solve), ~5k repeats, unboxed and suspicious
 * solves, finished_at on half of them, a secret puzzle - every value from md5 of its name, so the same rows every run.
 * The fixtures' own times are marked suspicious, so a change to the fixtures does not move it.
 *
 * Two runs: the first writes everything, the second after some stored rows were moved, dropped or added - so the
 * steps' "write only what changed" paths are compared too.
 *
 * A deliberate change of what the batch computes changes this record: re-record it with INSIGHTS_BATCH_PARITY_RECORD=1
 * and say in the change why the numbers moved.
 */
final class InsightsBatchParityTest extends KernelTestCase
{
    private const string RECORD = __DIR__ . '/InsightsBatchParity.json';
    private const int PLAYERS = 126;
    private const int PUZZLES = 170;
    private const int BRANDS = 3;

    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheBatchWritesWhatItWroteBefore(): void
    {
        /** @var PuzzleIntelligenceRecalculator $batch */
        $batch = self::getContainer()->get(PuzzleIntelligenceRecalculator::class);

        $this->createSyntheticData();
        $actual = [];

        $first = $batch->recalculate();
        $actual['first_run'] = ['counts' => self::counts($first)] + $this->snapshot();

        $this->moveStoredRows();
        $second = $batch->recalculate();
        $actual['second_run'] = ['counts' => self::counts($second)] + $this->snapshot();

        if (getenv('INSIGHTS_BATCH_PARITY_RECORD') === '1') {
            file_put_contents(self::RECORD, json_encode($actual, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) . "\n");
            self::markTestIncomplete('Recorded ' . self::RECORD);
        }

        $expected = json_decode((string) file_get_contents(self::RECORD), true, flags: JSON_THROW_ON_ERROR);
        // Compared as JSON text: a float differing in its last bit fails, and the diff shows where
        self::assertSame(
            json_encode($expected, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            json_encode($actual, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        );

        // Every step computed something - the record covers each of them, not a table of nulls
        $puzzles = $this->syntheticPuzzles();
        /** @var array<string, int|string> $metrics */
        $metrics = $this->database->fetchAssociative("
            SELECT COUNT(memorability_score) AS memorability, COUNT(skill_sensitivity_score) AS sensitivity,
                COUNT(predictability_score) AS predictability, COUNT(box_dependence_score) AS box_dependence,
                COUNT(improvement_ceiling_score) AS improvement_ceiling
            FROM puzzle_difficulty WHERE puzzle_id IN ({$puzzles})
        ");

        foreach ($metrics as $metric => $count) {
            self::assertGreaterThanOrEqual(20, (int) $count, $metric);
        }

        self::assertGreaterThan(0, $first['baselines_interpolated']);
        self::assertGreaterThan(50, $first['skills']);
        self::assertGreaterThan(20, $first['rating']);
        self::assertGreaterThan(0, $first['rating_entries']['written']);
        self::assertGreaterThan(0, $second['rating_entries']['written'] + $second['rating_entries']['deleted']);
        self::assertGreaterThan(0, $first['improvement_ratios']);
        self::assertNotEquals(1.3, $first['scaling_exponent'], 'the scaling exponent is fitted, not the default');
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private static function counts(array $result): array
    {
        unset($result['durations'], $result['peak_memory'], $result['resident_peak'], $result['generation']);

        return $result;
    }

    /**
     * What the batch wrote for the synthetic players and puzzles, each table as md5 of its rows rendered by Postgres
     * (every column but a generated id, doubles in their shortest exact form).
     *
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $players = $this->syntheticPlayers();
        $puzzles = $this->syntheticPuzzles();
        $tables = [
            'player_baseline' => "SELECT player_id, pieces_count, baseline_seconds, qualifying_solves_count, baseline_type, computed_at FROM player_baseline WHERE player_id IN ({$players}) ORDER BY player_id, pieces_count",
            'player_pace_history' => "SELECT time_id, player_id, pieces_count, valid_from, valid_from_tracked_at, qualifying_count, baseline_seconds, drift, spread FROM player_pace_history WHERE player_id IN ({$players}) ORDER BY time_id",
            'player_improvement_ratio' => "SELECT player_id, from_attempt, median_ratio, sample_size, computed_at FROM player_improvement_ratio WHERE player_id IN ({$players}) ORDER BY player_id, from_attempt",
            'global_improvement_ratio' => 'SELECT pieces_count, from_attempt, gap_bucket, median_ratio, sample_size, computed_at FROM global_improvement_ratio ORDER BY pieces_count, from_attempt, gap_bucket',
            'puzzle_difficulty' => "SELECT puzzle_id, difficulty_score, difficulty_tier, confidence, sample_size, indices_p25, indices_p75, sum_log_indices, computed_at FROM puzzle_difficulty WHERE puzzle_id IN ({$puzzles}) ORDER BY puzzle_id",
            'derived_metrics' => "SELECT puzzle_id, memorability_score, skill_sensitivity_score, predictability_score, box_dependence_score, improvement_ceiling_score FROM puzzle_difficulty WHERE puzzle_id IN ({$puzzles}) ORDER BY puzzle_id",
            'player_skill' => "SELECT player_id, pieces_count, skill_score, skill_tier, skill_percentile, confidence, qualifying_puzzles_count, computed_at FROM player_skill WHERE player_id IN ({$players}) ORDER BY player_id, pieces_count",
            'player_elo' => "SELECT player_id, pieces_count, elo_rating, computed_at FROM player_elo WHERE player_id IN ({$players}) ORDER BY player_id, pieces_count",
            'player_rating_entry' => "SELECT player_id, pieces_count, puzzle_id, portfolio, seconds, solved_at, percentile, difficulty_weight, decay, points, in_top, computed_at FROM player_rating_entry WHERE player_id IN ({$players}) ORDER BY player_id, pieces_count, puzzle_id, portfolio",
            'puzzle_rating_times' => "SELECT puzzle_id, pieces_count, solver_count, qualifies, difficulty_weight, first_attempt_times, best_times, computed_at FROM puzzle_rating_times WHERE puzzle_id IN ({$puzzles}) ORDER BY puzzle_id",
            'player_skill_history' => "SELECT player_id, pieces_count, month, baseline_seconds, skill_tier, skill_percentile FROM player_skill_history WHERE player_id IN ({$players}) ORDER BY player_id, pieces_count, month",
            'player_rating_snapshot' => "SELECT player_id, pieces_count, snapshot_date, skill_score, skill_tier, skill_percentile, elo_rating, elo_rank, baseline_seconds, baseline_type, computed_at FROM player_rating_snapshot WHERE player_id IN ({$players}) ORDER BY player_id, pieces_count, snapshot_date",
        ];
        $snapshot = [];

        foreach ($tables as $table => $sql) {
            /** @var array{rows: int|string, digest: string} $row */
            $row = $this->database->fetchAssociative("SELECT COUNT(*) AS rows, md5(COALESCE(string_agg(CAST(t AS text), E'\\n'), '')) AS digest FROM ({$sql}) t");
            $snapshot[$table] = $row['rows'] . ' rows, md5 ' . $row['digest'];
        }

        return $snapshot;
    }

    private function createSyntheticData(): void
    {
        $now = TestClock::SQL_NOW;

        // The fixtures' times are no solve of anybody's insights
        $this->database->executeStatement('UPDATE puzzle_solving_time SET suspicious = true');

        $this->database->executeStatement("
            INSERT INTO manufacturer (id, name, approved, slug)
            SELECT CAST(md5('bpar-brand-' || b) AS uuid), 'Batch parity brand ' || b, true, 'bpar-brand-' || b
            FROM generate_series(0, " . (self::BRANDS - 1) . ') b
        ');
        // Every 20th player private, every 25th opted out of rankings
        $this->database->executeStatement("
            INSERT INTO player (id, code, name, registered_at, is_private, ranking_opted_out)
            SELECT CAST(md5('bpar-player-' || n) AS uuid), 'bpar' || n, 'Batch parity ' || n, {$now} - INTERVAL '1000 days', n % 20 = 0, n % 25 = 0
            FROM generate_series(1, " . self::PLAYERS . ') n
        ');
        // Sizes by n % 17: 11 × 500, 2 × 1000, 2 × 300, 1 × 750, 1 × 1500; puzzle 3 is secret
        $this->database->executeStatement("
            INSERT INTO puzzle (id, name, pieces_count, approved, is_available, manufacturer_id, hide_until)
            SELECT CAST(md5('bpar-puzzle-' || n) AS uuid), 'Batch parity puzzle ' || n,
                CASE WHEN n % 17 < 11 THEN 500 WHEN n % 17 < 13 THEN 1000 WHEN n % 17 < 15 THEN 300 WHEN n % 17 = 15 THEN 750 ELSE 1500 END,
                true, true, CAST(md5('bpar-brand-' || (n % " . self::BRANDS . ")) AS uuid),
                CASE WHEN n = 3 THEN {$now} + INTERVAL '30 days' END
            FROM generate_series(1, " . self::PUZZLES . ') n
        ');

        // First solves: each player solves a share of the puzzles (35-90 %, a third of that at 750 and 1500 pieces, so
        // some players have too few there for a direct baseline), over the last 27 months - time = the player's pace ×
        // size^1.35 × the puzzle's difficulty × noise. One in ten not flagged as a first attempt (the eldest solve
        // counts), half with a finished_at, one in seven unboxed, one in fifty suspicious.
        $this->database->executeStatement(sprintf(
            "INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt, unboxed, suspicious)
            SELECT CAST(md5('bpar-time-' || u.n || '-' || z.n) AS uuid), CAST(md5('bpar-player-' || u.n) AS uuid), p.id,
                GREATEST(60, CAST(round((2000 + 4000 * %s) * power(p.pieces_count / 500.0, 1.35) * exp(%s) * exp((%s - 0.5) * 0.5)) AS int)),
                solved.at,
                CASE WHEN %s < 0.5 THEN solved.at - make_interval(mins => 30 + CAST(floor(%s * 600) AS int)) END,
                false, %s >= 0.1, %s < 0.14, %s < 0.02
            FROM generate_series(1, %d) u (n)
            CROSS JOIN generate_series(1, %d) z (n)
            JOIN puzzle p ON p.id = CAST(md5('bpar-puzzle-' || z.n) AS uuid)
            CROSS JOIN LATERAL (SELECT %s - make_interval(secs => CAST(round(%s * 820 * 86400) AS int)) AS at) solved
            WHERE %s < (0.35 + 0.55 * %s) * CASE WHEN p.pieces_count IN (750, 1500) THEN 0.35 ELSE 1 END",
            self::uniform("'bpar-pace-' || u.n"),
            self::trueLog('z.n'),
            self::uniform("'bpar-noise-' || u.n || '-' || z.n"),
            self::uniform("'bpar-finished-' || u.n || '-' || z.n"),
            self::uniform("'bpar-finished-mins-' || u.n || '-' || z.n"),
            self::uniform("'bpar-flag-' || u.n || '-' || z.n"),
            self::uniform("'bpar-unboxed-' || u.n || '-' || z.n"),
            self::uniform("'bpar-suspicious-' || u.n || '-' || z.n"),
            self::PLAYERS,
            self::PUZZLES,
            $now,
            self::uniform("'bpar-day-' || u.n || '-' || z.n"),
            self::uniform("'bpar-solves-' || u.n || '-' || z.n"),
            self::uniform("'bpar-share-' || u.n"),
        ));

        // Repeats: a second solve of 35 % of the first ones and a third of half of those, 5-300 days later, faster -
        // one in seven unboxed, half with a finished_at (`comment` marks which solve of the puzzle a row is)
        $this->database->executeStatement("UPDATE puzzle_solving_time SET comment = 'bpar-1' WHERE player_id IN (" . $this->syntheticPlayers() . ')');

        foreach ([2 => 0.35, 3 => 0.5] as $attempt => $share) {
            $this->database->executeStatement(sprintf(
                "INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, finished_at, verified, first_attempt, unboxed, comment)
                SELECT CAST(md5('bpar-repeat-' || prior.id) AS uuid), prior.player_id, prior.puzzle_id,
                    GREATEST(60, CAST(round(prior.seconds_to_solve * (0.7 + 0.35 * %s)) AS int)),
                    prior.tracked_at + make_interval(days => gap.days),
                    CASE WHEN %s < 0.5 THEN prior.tracked_at + make_interval(days => gap.days) - INTERVAL '45 minutes' END,
                    false, false, %s < 0.14, 'bpar-%d'
                FROM puzzle_solving_time prior
                CROSS JOIN LATERAL (SELECT 5 + CAST(floor(%s * 295) AS int) AS days) gap
                WHERE prior.comment = 'bpar-%d'
                    AND prior.suspicious = false
                    AND %s < %s
                    AND prior.tracked_at + make_interval(days => gap.days) < %s",
                self::uniform("'bpar-repeat-time-' || prior.id"),
                self::uniform("'bpar-repeat-finished-' || prior.id"),
                self::uniform("'bpar-repeat-unboxed-' || prior.id"),
                $attempt,
                self::uniform("'bpar-repeat-gap-' || prior.id"),
                $attempt - 1,
                self::uniform("'bpar-repeats-' || prior.id"),
                $share,
                $now,
            ));
        }
    }

    /**
     * Some stored rows off, dropped or added, so the second run has something to write in every step
     */
    private function moveStoredRows(): void
    {
        $players = $this->syntheticPlayers();
        $puzzles = $this->syntheticPuzzles();
        $statements = [
            "DELETE FROM player_baseline WHERE player_id IN ({$players}) AND " . self::uniform("CAST(player_id AS text) || pieces_count || 'drop'") . ' < 0.1',
            "UPDATE player_improvement_ratio SET median_ratio = median_ratio + 0.01 WHERE player_id IN ({$players}) AND " . self::uniform("CAST(player_id AS text) || from_attempt || 'off'") . ' < 0.2',
            "UPDATE puzzle_difficulty SET memorability_score = NULL, predictability_score = predictability_score + 0.001 WHERE puzzle_id IN ({$puzzles}) AND " . self::uniform("CAST(puzzle_id AS text) || 'off'") . ' < 0.2',
            "DELETE FROM player_skill WHERE player_id IN ({$players}) AND " . self::uniform("CAST(player_id AS text) || 'drop'") . ' < 0.1',
            "UPDATE player_elo SET elo_rating = elo_rating + 1 WHERE player_id IN ({$players}) AND " . self::uniform("CAST(player_id AS text) || 'off'") . ' < 0.2',
            "DELETE FROM player_rating_entry WHERE player_id IN ({$players}) AND " . self::uniform("CAST(player_id AS text) || CAST(puzzle_id AS text) || portfolio || 'drop'") . ' < 0.05',
            "UPDATE player_rating_entry SET points = points + 0.5 WHERE player_id IN ({$players}) AND " . self::uniform("CAST(player_id AS text) || CAST(puzzle_id AS text) || portfolio || 'off'") . ' < 0.05',
            "UPDATE puzzle_rating_times SET solver_count = solver_count + 1 WHERE puzzle_id IN ({$puzzles}) AND " . self::uniform("CAST(puzzle_id AS text) || 'off'") . ' < 0.2',
            // A player's rows the run no longer produces: their stale entries go
            "INSERT INTO player_rating_entry (player_id, pieces_count, puzzle_id, portfolio, seconds, solved_at, percentile, difficulty_weight, decay, points, in_top, computed_at)
                SELECT CAST(md5('bpar-player-1') AS uuid), 1000, CAST(md5('bpar-puzzle-12') AS uuid), 'best_time', 100, " . TestClock::SQL_NOW . ', 0.5, 1.0, 1.0, 0.5, true, ' . TestClock::SQL_NOW . " - INTERVAL '1 day'",
        ];

        foreach ($statements as $sql) {
            $this->database->executeStatement($sql);
        }
    }

    /**
     * The true log difficulty of puzzle number $n (an SQL expression)
     */
    private static function trueLog(string $n): string
    {
        return '((' . self::uniform("'bpar-d1-' || {$n}") . ' + ' . self::uniform("'bpar-d2-' || {$n}") . ' + ' . self::uniform("'bpar-d3-' || {$n}") . ' - 1.5) * 0.4)';
    }

    private function syntheticPlayers(): string
    {
        return "SELECT CAST(md5('bpar-player-' || n) AS uuid) FROM generate_series(1, " . self::PLAYERS . ') n';
    }

    private function syntheticPuzzles(): string
    {
        return "SELECT CAST(md5('bpar-puzzle-' || n) AS uuid) FROM generate_series(1, " . self::PUZZLES . ') n';
    }

    /**
     * A number in [0, 1) from md5 of an SQL text expression - the same every run.
     */
    private static function uniform(string $expression): string
    {
        return "(CAST(CAST('x' || substr(md5({$expression}), 1, 8) AS bit(32)) AS bigint) & 2147483647) / 2147483648.0";
    }
}
