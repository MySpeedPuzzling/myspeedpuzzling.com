<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\FitPredictionModel;
use SpeedPuzzling\Web\Query\GetPlayerPredictions;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The shown model's jobs give exactly what they gave before their memory was cut (2026-10-27, docs/features/
 * puzzle-intelligence/README.md "Memory of the daily fit"): the batch's difficulty and drift, the daily fit's
 * parameters, priors and drift, the batch's drift with that fit, and the predictions read from all of it - compared to
 * the bit with what the code before the change wrote on the same synthetic data (ShownModelParity.json, recorded by
 * that code; every fitted part has enough samples to be fitted, not defaulted).
 *
 * The data: 160 players and 1,500 puzzles of five sizes from four brands, three in four with a picture estimate,
 * ~10,700 first tries over 18 months and ~2,700 repeats with a stored personal prediction - every value from md5 of
 * its name, so the same rows every run. The fixtures' own times are marked suspicious (no qualifying attempt, no
 * repeat), so a change to the fixtures does not move it.
 *
 * A deliberate change of what the shown model computes changes this record: re-record it with
 * SHOWN_MODEL_PARITY_RECORD=1 and say in the change why the numbers moved.
 */
final class ShownModelParityTest extends KernelTestCase
{
    private const string RECORD = __DIR__ . '/ShownModelParity.json';
    private const int PLAYERS = 160;
    private const int PUZZLES = 1500;
    private const int BRANDS = 4;

    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheShownModelComputesWhatItComputedBefore(): void
    {
        $container = self::getContainer();
        /** @var PuzzleIntelligenceRecalculator $batch */
        $batch = $container->get(PuzzleIntelligenceRecalculator::class);
        /** @var MessageBusInterface $messageBus */
        $messageBus = $container->get(MessageBusInterface::class);

        $this->createSyntheticData();
        $actual = [];

        $first = $batch->recalculate();
        $actual['batch_before_fit'] = ['drift_rows_written' => $first['drift_rows_written'], 'difficulties' => $first['difficulties']] + $this->snapshot();

        $messageBus->dispatch(new FitPredictionModel());
        /** @var string $parameters */
        $parameters = $this->database->fetchOne('SELECT CAST(parameters AS text) FROM prediction_model_fit WHERE model_version = :version', ['version' => TimePredictionCalculator::MODEL_VERSION]);
        $actual['fit'] = ['parameters' => json_decode($parameters, true, flags: JSON_THROW_ON_ERROR)] + $this->snapshot();

        // Some stored drift off, so the batch has rows to write with the fit
        $this->database->executeStatement('
            UPDATE player_pace_history SET drift = drift + 0.01
            WHERE player_id IN (' . $this->syntheticPlayers() . ') AND drift IS NOT NULL AND ' . self::uniform("time_id::text || 'off'") . ' < 0.2
        ');
        $second = $batch->recalculate();
        $actual['batch_after_fit'] = ['drift_rows_written' => $second['drift_rows_written'], 'difficulties' => $second['difficulties']] + $this->snapshot();
        $actual['predictions'] = $this->predictions();

        if (getenv('SHOWN_MODEL_PARITY_RECORD') === '1') {
            file_put_contents(self::RECORD, json_encode($actual, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) . "\n");
            self::markTestIncomplete('Recorded ' . self::RECORD);
        }

        $expected = json_decode((string) file_get_contents(self::RECORD), true, flags: JSON_THROW_ON_ERROR);
        // Compared as JSON text: a float differing in its last bit fails, and the diff shows where
        self::assertSame(
            json_encode($expected, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            json_encode($actual, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        );

        // Every part of the fit was fitted, not defaulted - the record covers each of its steps
        /** @var array{summary: array<string, int|float|string>, thin_sizes: array<string, mixed>, first_try: array{image_window_scales: array<string, mixed>}} $fitted */
        $fitted = $actual['fit']['parameters'];
        self::assertGreaterThanOrEqual(500, $fitted['summary']['tau_samples']);
        self::assertGreaterThanOrEqual(2000, $fitted['summary']['first_try_samples']);
        self::assertGreaterThanOrEqual(2000, $fitted['summary']['repeat_samples']);
        self::assertGreaterThanOrEqual(2000, $fitted['summary']['image_window_samples']);
        self::assertNotSame([], $fitted['thin_sizes']);
        self::assertGreaterThan(1, count($fitted['first_try']['image_window_scales']));
        self::assertGreaterThan(0, $actual['batch_after_fit']['drift_rows_written']);
    }

    /**
     * What the jobs wrote for the synthetic players and puzzles, each table as md5 of its rows rendered by Postgres
     * (every column, doubles in their shortest exact form).
     *
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $players = $this->syntheticPlayers();
        $puzzles = $this->syntheticPuzzles();
        $brands = $this->syntheticBrands();
        $tables = [
            'player_pace_history' => "SELECT time_id, player_id, pieces_count, valid_from, valid_from_tracked_at, qualifying_count, baseline_seconds, drift, spread FROM player_pace_history WHERE player_id IN ({$players}) ORDER BY time_id",
            'puzzle_difficulty' => "SELECT puzzle_id, difficulty_score, difficulty_tier, confidence, sample_size, indices_p25, indices_p75, sum_log_indices, prior_log, blend_prior_log, computed_at FROM puzzle_difficulty WHERE puzzle_id IN ({$puzzles}) ORDER BY puzzle_id",
            'difficulty_prior_group' => "SELECT manufacturer_id, pieces_count, sum_log_indices, index_count, computed_at FROM difficulty_prior_group WHERE manufacturer_id IN ({$brands}) ORDER BY manufacturer_id, pieces_count",
            'difficulty_prior_puzzle' => "SELECT puzzle_id, sum_log_indices, index_count, prior_log, blend_prior_log, computed_at FROM difficulty_prior_puzzle WHERE puzzle_id IN ({$puzzles}) ORDER BY puzzle_id",
        ];
        $snapshot = [];

        foreach ($tables as $table => $sql) {
            /** @var array{rows: int|string, digest: string} $row */
            $row = $this->database->fetchAssociative("SELECT COUNT(*) AS rows, md5(COALESCE(string_agg(CAST(t AS text), E'\\n'), '')) AS digest FROM ({$sql}) t");
            $snapshot[$table] = $row['rows'] . ' rows, md5 ' . $row['digest'];
        }

        return $snapshot;
    }

    /**
     * Every synthetic puzzle's shown prediction for five players (their solved puzzles personal, the rest statistical).
     *
     * @return array<string, string>
     */
    private function predictions(): array
    {
        /** @var GetPlayerPredictions $getPlayerPredictions */
        $getPlayerPredictions = self::getContainer()->get(GetPlayerPredictions::class);
        /** @var list<string> $puzzles */
        $puzzles = $this->database->fetchFirstColumn('SELECT id FROM puzzle WHERE id IN (' . $this->syntheticPuzzles() . ') ORDER BY id');
        $digests = [];

        foreach ([1, 7, 42, 99, 150] as $player) {
            $predictions = $getPlayerPredictions->forPuzzles(self::id('player', $player), $puzzles);
            ksort($predictions);
            $rendered = json_encode(array_map(static fn (object $prediction): array => get_object_vars($prediction), $predictions), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $digests['player_' . $player] = count($predictions) . ' predictions, md5 ' . md5($rendered);
        }

        return $digests;
    }

    private function createSyntheticData(): void
    {
        $now = TestClock::SQL_NOW;

        // The fixtures' times are no qualifying first attempt and no repeat of anybody
        $this->database->executeStatement('UPDATE puzzle_solving_time SET suspicious = true');

        $this->database->executeStatement("
            INSERT INTO manufacturer (id, name, approved, slug)
            SELECT CAST(md5('parity-brand-' || b) AS uuid), 'Parity brand ' || b, true, 'parity-brand-' || b
            FROM generate_series(0, " . (self::BRANDS - 1) . ') b
        ');
        $this->database->executeStatement("
            INSERT INTO player (id, code, name, registered_at)
            SELECT CAST(md5('parity-player-' || n) AS uuid), 'parity' || n, 'Parity ' || n, {$now} - INTERVAL '900 days'
            FROM generate_series(1, " . self::PLAYERS . ') n
        ');
        // Sizes by n % 20: 12 × 500, 3 × 1000, 3 × 300, 1 × 1500, 1 × 750
        $this->database->executeStatement("
            INSERT INTO puzzle (id, name, pieces_count, approved, is_available, manufacturer_id, image)
            SELECT CAST(md5('parity-puzzle-' || n) AS uuid), 'Parity puzzle ' || n,
                CASE WHEN n % 20 < 12 THEN 500 WHEN n % 20 < 15 THEN 1000 WHEN n % 20 < 18 THEN 300 WHEN n % 20 = 18 THEN 1500 ELSE 750 END,
                true, true, CAST(md5('parity-brand-' || (n % " . self::BRANDS . ")) AS uuid), 'parity/' || n || '.jpg'
            FROM generate_series(1, " . self::PUZZLES . ') n
        ');

        // The puzzles' true log difficulty, a picture estimate of it for three in four, half of those with their own 50 % ends
        $this->database->executeStatement("
            INSERT INTO puzzle_difficulty_estimate (puzzle_id, model, model_version, log_difficulty, log_p10, log_p90, log_p25, log_p75, out_of_fold, training_size, image_fingerprint, computed_at, received_at)
            SELECT p.id, 'parity-ridge', '1.0.0', e.log, e.log - 0.22, e.log + 0.25,
                CASE WHEN n % 2 = 0 THEN e.log - 0.09 END, CASE WHEN n % 2 = 0 THEN e.log + 0.11 END,
                true, 1000, " . PuzzleImageFingerprint::sql('p.image') . ", {$now}, {$now}
            FROM generate_series(1, " . self::PUZZLES . ") n
            JOIN puzzle p ON p.id = CAST(md5('parity-puzzle-' || n) AS uuid)
            CROSS JOIN LATERAL (SELECT " . self::trueLog('n') . ' + (' . self::uniform("'parity-e-' || n") . " - 0.5) * 0.3 AS log) e
            WHERE n % 4 <> 3
        ");
        $this->database->executeStatement("
            INSERT INTO puzzle_image_analysis (id, puzzle_id, analyzer, analyzer_version, image_fingerprint, result, received_at)
            SELECT CAST(md5('parity-artwork-' || n) AS uuid), p.id, 'artwork', '1.1.0', " . PuzzleImageFingerprint::sql('p.image') . ",
                CAST(CASE n % 3 WHEN 0 THEN '{\"kind\": \"flat\"}' WHEN 1 THEN '{\"kind\": \"box_front\"}' ELSE '{\"kind\": \"assembled\"}' END AS jsonb), {$now}
            FROM generate_series(1, " . self::PUZZLES . ") n
            JOIN puzzle p ON p.id = CAST(md5('parity-puzzle-' || n) AS uuid)
            WHERE n % 4 <> 3
        ");

        // First tries: each player a share of each size (fewer players at the rarer sizes), solved over the last 18
        // months - time = the player's pace × size^1.35 × the puzzle's difficulty × noise
        $share = "CASE
            WHEN p.pieces_count = 500 THEN 40.0 / 900
            WHEN p.pieces_count = 1000 AND u.n <= 110 THEN 20.0 / 225
            WHEN p.pieces_count = 300 AND u.n > 20 AND u.n <= 110 THEN 15.0 / 225
            WHEN p.pieces_count = 1500 AND u.n <= 50 THEN 6.0 / 75
            WHEN p.pieces_count = 750 AND u.n <= 60 THEN 8.0 / 75
            ELSE 0 END";
        $this->database->executeStatement(sprintf(
            "INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, verified, first_attempt)
            SELECT CAST(md5('parity-time-' || u.n || '-' || z.n) AS uuid), CAST(md5('parity-player-' || u.n) AS uuid), p.id,
                GREATEST(60, CAST(round((2400 + 3600 * %s) * power(p.pieces_count / 500.0, 1.35) * exp(%s) * exp((%s - 0.5) * 0.5)) AS int)),
                %s - make_interval(secs => round(%s * %s * 86400)),
                false, true
            FROM generate_series(1, %d) u (n)
            CROSS JOIN generate_series(1, %d) z (n)
            JOIN puzzle p ON p.id = CAST(md5('parity-puzzle-' || z.n) AS uuid)
            WHERE %s < %s",
            self::uniform("'parity-pace-' || u.n"),
            self::trueLog('z.n'),
            self::uniform("'parity-noise-' || u.n || '-' || z.n"),
            $now,
            self::uniform("'parity-day-' || u.n || '-' || z.n"),
            // Solved between the puzzle's release (0-540 days ago) and now - new puzzles every week, as on the site
            '(540 * ' . self::uniform("'parity-release-' || z.n") . ')',
            self::PLAYERS,
            self::PUZZLES,
            self::uniform("'parity-solves-' || u.n || '-' || z.n"),
            $share,
        ));

        // Repeats of 500 and 1000 piece first tries, 20-150 days later, with the stored personal prediction a repeat's
        // window calibrates on
        $this->database->executeStatement(sprintf(
            "INSERT INTO puzzle_solving_time (id, player_id, puzzle_id, seconds_to_solve, tracked_at, verified, first_attempt, predictable, prediction_method, predicted_seconds)
            SELECT CAST(md5('parity-repeat-' || first.id) AS uuid), first.player_id, first.puzzle_id,
                GREATEST(60, CAST(round(first.seconds_to_solve * (0.75 + 0.2 * %s)) AS int)),
                first.tracked_at + make_interval(days => gap.days),
                false, false, true, 'personal', CAST(round(first.seconds_to_solve * 0.88) AS int)
            FROM puzzle_solving_time first
            JOIN puzzle p ON p.id = first.puzzle_id
            CROSS JOIN LATERAL (SELECT 20 + CAST(floor(%s * 130) AS int) AS days) gap
            WHERE first.player_id IN (%s)
                AND p.pieces_count IN (500, 1000)
                AND %s < 0.9
                AND first.tracked_at + make_interval(days => gap.days) < %s",
            self::uniform("'parity-repeat-time-' || first.id"),
            self::uniform("'parity-repeat-gap-' || first.id"),
            $this->syntheticPlayers(),
            self::uniform("'parity-repeats-' || first.id"),
            $now,
        ));
    }

    /**
     * The true log difficulty of puzzle number $n (an SQL expression)
     */
    private static function trueLog(string $n): string
    {
        return '((' . self::uniform("'parity-d1-' || {$n}") . ' + ' . self::uniform("'parity-d2-' || {$n}") . ' + ' . self::uniform("'parity-d3-' || {$n}") . ' - 1.5) * 0.4)';
    }

    private function syntheticPlayers(): string
    {
        return "SELECT CAST(md5('parity-player-' || n) AS uuid) FROM generate_series(1, " . self::PLAYERS . ') n';
    }

    private function syntheticPuzzles(): string
    {
        return "SELECT CAST(md5('parity-puzzle-' || n) AS uuid) FROM generate_series(1, " . self::PUZZLES . ') n';
    }

    private function syntheticBrands(): string
    {
        return "SELECT CAST(md5('parity-brand-' || b) AS uuid) FROM generate_series(0, " . (self::BRANDS - 1) . ') b';
    }

    /**
     * A number in [0, 1) from md5 of an SQL text expression - the same every run.
     */
    private static function uniform(string $expression): string
    {
        return "(CAST(CAST('x' || substr(md5({$expression}), 1, 8) AS bit(32)) AS bigint) & 2147483647) / 2147483648.0";
    }

    private static function id(string $kind, int $n): string
    {
        $hex = md5('parity-' . $kind . '-' . $n);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
