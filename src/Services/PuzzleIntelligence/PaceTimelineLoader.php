<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Loads player_pace_history into a PaceTimeline: every row (the batch), every row of some players
 * (the reconstructor), or only what a few lookups need (one puzzle - the incremental handler on
 * every added time).
 */
readonly final class PaceTimelineLoader
{
    private const int PLAYER_CHUNK_SIZE = 1000;

    private const string COLUMNS = '
        h.player_id,
        h.pieces_count,
        EXTRACT(EPOCH FROM h.valid_from) AS solved_ts,
        EXTRACT(EPOCH FROM h.valid_from_tracked_at) AS tracked_ts,
        h.baseline_seconds,
        h.drift,
        h.spread
    ';

    public function __construct(
        private Connection $connection,
        private PlayerBaselineCalculator $baselineCalculator,
    ) {
    }

    /**
     * Every row, without their drift and spread for the lookups (the batch and the evaluation compute their own -
     * PredictionEngine); with $withStoredStates the stored drift and spread per row on the side
     * (PaceTimeline::storedStates() - what the prediction model's step writes only the changes against).
     */
    public function all(null|float $scalingExponent = null, bool $withStoredStates = false): PaceTimeline
    {
        $timeline = new PaceTimeline($this->baselineCalculator, $scalingExponent, withStates: false);
        $states = $withStoredStates ? 'h.qualifying_count, h.drift, h.spread' : 'NULL AS qualifying_count, NULL AS drift, NULL AS spread';

        $rows = $this->connection->executeQuery(
            "SELECT h.player_id, h.pieces_count, EXTRACT(EPOCH FROM h.valid_from) AS solved_ts, EXTRACT(EPOCH FROM h.valid_from_tracked_at) AS tracked_ts, h.baseline_seconds, {$states}
            FROM player_pace_history h ORDER BY h.player_id, h.pieces_count, h.valid_from, h.valid_from_tracked_at, h.qualifying_count",
        )->iterateNumeric();

        foreach ($rows as $row) {
            /** @var array{0: string, 1: int|string, 2: float|int|string, 3: float|int|string, 4: int|string, 5: null|int|string, 6: null|float|string, 7: null|float|string} $row */
            $timeline->add($row[0], (int) $row[1], (float) $row[2], (float) $row[3], (int) $row[4]);

            if ($withStoredStates) {
                $timeline->addStoredState($row[0], (int) $row[1], (int) $row[5], $row[6] !== null ? (float) $row[6] : null, $row[7] !== null ? (float) $row[7] : null);
            }
        }

        return $timeline;
    }

    /**
     * @param list<string> $playerIds
     */
    public function forPlayers(array $playerIds, null|float $scalingExponent = null): PaceTimeline
    {
        $timeline = new PaceTimeline($this->baselineCalculator, $scalingExponent);
        $this->addRowsOfPlayers($timeline, $playerIds);

        return $timeline;
    }

    /**
     * The qualifying first attempts of one puzzle with just enough pace rows for their lookups to come
     * out as on the whole timeline - all in one statement (the incremental handler runs it on every
     * added time): whether the history is built at all (pace_history_build, and the batch's scaling
     * exponent), the row valid just before each solve at the puzzle's piece count (one index step
     * each), and every row of a solver without one (they need their other piece counts or their
     * first pace - a few, usually; aggregated per solver).
     *
     * @return null|array{0: list<array{player_id: string, pieces_count: int|string, seconds_to_solve: int|string, solved_ts: float|int|string, tracked_ts: float|int|string}>, 1: PaceTimeline} null = the history is not built yet
     */
    public function forPuzzleFirstAttempts(string $puzzleId): null|array
    {
        [$qualifying, $params, $types] = $this->baselineCalculator->qualifyingFirstAttemptsQuery(puzzleId: $puzzleId);

        /** @var list<array{scaling_exponent: null|float|string, built: bool, player_id: null|string, pieces_count: null|int|string, seconds_to_solve: null|int|string, solved_ts: null|float|int|string, tracked_ts: null|float|int|string, pace_solved_ts: null|float|int|string, pace_tracked_ts: null|float|int|string, pace_baseline_seconds: null|int|string, all_rows: null|string}> $rows */
        $rows = $this->connection->fetchAllAssociative("
            SELECT
                b.id IS NOT NULL AS built,
                COALESCE(b.scaling_exponent, " . PlayerBaselineCalculator::SCALING_EXPONENT_DEFAULT . ") AS scaling_exponent,
                q.player_id,
                q.pieces_count,
                q.seconds_to_solve,
                EXTRACT(EPOCH FROM q.solve_date) AS solved_ts,
                EXTRACT(EPOCH FROM q.tracked_at) AS tracked_ts,
                EXTRACT(EPOCH FROM h.valid_from) AS pace_solved_ts,
                EXTRACT(EPOCH FROM h.valid_from_tracked_at) AS pace_tracked_ts,
                h.baseline_seconds AS pace_baseline_seconds,
                every_row.all_rows
            FROM (SELECT 1) AS one
            LEFT JOIN pace_history_build b ON b.id = 1
            LEFT JOIN ({$qualifying}) q ON b.id IS NOT NULL
            LEFT JOIN LATERAL (
                SELECT row_before.valid_from, row_before.valid_from_tracked_at, row_before.baseline_seconds
                FROM player_pace_history row_before
                WHERE row_before.player_id = q.player_id
                    AND row_before.pieces_count = q.pieces_count
                    AND (row_before.valid_from, row_before.valid_from_tracked_at) < (q.solve_date, q.tracked_at)
                ORDER BY row_before.valid_from DESC, row_before.valid_from_tracked_at DESC, row_before.qualifying_count DESC
                LIMIT 1
            ) h ON true
            LEFT JOIN LATERAL (
                SELECT json_agg(
                    json_build_array(r.pieces_count, EXTRACT(EPOCH FROM r.valid_from), EXTRACT(EPOCH FROM r.valid_from_tracked_at), r.baseline_seconds)
                    ORDER BY r.pieces_count, r.valid_from, r.valid_from_tracked_at, r.qualifying_count
                ) AS all_rows
                FROM player_pace_history r
                WHERE h.baseline_seconds IS NULL
                    AND r.player_id = q.player_id
            ) every_row ON true
        ", $params, $types);

        if ($rows === [] || $rows[0]['built'] === false) {
            return null;
        }

        $timeline = new PaceTimeline($this->baselineCalculator, (float) $rows[0]['scaling_exponent']);
        $firstAttempts = [];

        foreach ($rows as $row) {
            if ($row['player_id'] === null || $row['pieces_count'] === null || $row['seconds_to_solve'] === null || $row['solved_ts'] === null || $row['tracked_ts'] === null) {
                continue; // built, but the puzzle has no qualifying first attempt
            }

            $firstAttempts[] = [
                'player_id' => $row['player_id'],
                'pieces_count' => $row['pieces_count'],
                'seconds_to_solve' => $row['seconds_to_solve'],
                'solved_ts' => $row['solved_ts'],
                'tracked_ts' => $row['tracked_ts'],
            ];

            if ($row['pace_baseline_seconds'] !== null && $row['pace_solved_ts'] !== null && $row['pace_tracked_ts'] !== null) {
                $timeline->add($row['player_id'], (int) $row['pieces_count'], (float) $row['pace_solved_ts'], (float) $row['pace_tracked_ts'], (int) $row['pace_baseline_seconds']);

                continue;
            }

            /** @var list<array{0: int, 1: float|int, 2: float|int, 3: int}> $everyRow */
            $everyRow = $row['all_rows'] !== null ? json_decode($row['all_rows'], true, flags: JSON_THROW_ON_ERROR) : [];

            foreach ($everyRow as [$piecesCount, $solved, $tracked, $baseline]) {
                $timeline->add($row['player_id'], (int) $piecesCount, (float) $solved, (float) $tracked, (int) $baseline);
            }
        }

        return [$firstAttempts, $timeline];
    }

    /**
     * @param list<string> $playerIds
     */
    private function addRowsOfPlayers(PaceTimeline $timeline, array $playerIds): void
    {
        foreach (array_chunk($playerIds, self::PLAYER_CHUNK_SIZE) as $chunk) {
            $this->addRows($timeline, $this->connection->executeQuery(
                'SELECT ' . self::COLUMNS . ' FROM player_pace_history h WHERE h.player_id IN (:playerIds) ORDER BY h.player_id, h.pieces_count, h.valid_from, h.valid_from_tracked_at, h.qualifying_count',
                ['playerIds' => $chunk],
                ['playerIds' => ArrayParameterType::STRING],
            )->iterateAssociative());
        }
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     */
    private function addRows(PaceTimeline $timeline, iterable $rows): void
    {
        foreach ($rows as $row) {
            /** @var array{player_id: string, pieces_count: int|string, solved_ts: float|int|string, tracked_ts: float|int|string, baseline_seconds: int|string, drift: null|float|string, spread: null|float|string} $row */
            $timeline->add(
                $row['player_id'],
                (int) $row['pieces_count'],
                (float) $row['solved_ts'],
                (float) $row['tracked_ts'],
                (int) $row['baseline_seconds'],
                $row['drift'] !== null ? (float) $row['drift'] : null,
                $row['spread'] !== null ? (float) $row['spread'] : null,
            );
        }
    }
}
