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
        h.baseline_seconds
    ';

    public function __construct(
        private Connection $connection,
        private PlayerBaselineCalculator $baselineCalculator,
    ) {
    }

    public function all(null|float $scalingExponent = null): PaceTimeline
    {
        $timeline = new PaceTimeline($this->baselineCalculator, $scalingExponent);

        $this->addRows($timeline, $this->connection->executeQuery(
            'SELECT ' . self::COLUMNS . ' FROM player_pace_history h ORDER BY h.player_id, h.pieces_count, h.valid_from, h.valid_from_tracked_at, h.qualifying_count',
        )->iterateAssociative());

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
     * out as on the whole timeline - in one statement with the solves: the row valid just before each
     * solve at the puzzle's piece count (one index step each); plus every row of the solvers without
     * one (they need their other piece counts or their first pace - a few, usually). The incremental
     * handler runs this on every added time.
     *
     * @return array{0: list<array{player_id: string, pieces_count: int|string, seconds_to_solve: int|string, solved_ts: float|int|string, tracked_ts: float|int|string, pace_solved_ts: null|float|int|string, pace_tracked_ts: null|float|int|string, pace_baseline_seconds: null|int|string}>, 1: PaceTimeline}
     */
    public function forPuzzleFirstAttempts(string $puzzleId): array
    {
        $timeline = new PaceTimeline($this->baselineCalculator);
        [$qualifying, $params, $types] = $this->baselineCalculator->qualifyingFirstAttemptsQuery(puzzleId: $puzzleId);

        /** @var list<array{player_id: string, pieces_count: int|string, seconds_to_solve: int|string, solved_ts: float|int|string, tracked_ts: float|int|string, pace_solved_ts: null|float|int|string, pace_tracked_ts: null|float|int|string, pace_baseline_seconds: null|int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative("
            SELECT
                q.player_id,
                q.pieces_count,
                q.seconds_to_solve,
                EXTRACT(EPOCH FROM q.solve_date) AS solved_ts,
                EXTRACT(EPOCH FROM q.tracked_at) AS tracked_ts,
                EXTRACT(EPOCH FROM h.valid_from) AS pace_solved_ts,
                EXTRACT(EPOCH FROM h.valid_from_tracked_at) AS pace_tracked_ts,
                h.baseline_seconds AS pace_baseline_seconds
            FROM ({$qualifying}) q
            LEFT JOIN LATERAL (
                SELECT row_before.valid_from, row_before.valid_from_tracked_at, row_before.baseline_seconds
                FROM player_pace_history row_before
                WHERE row_before.player_id = q.player_id
                    AND row_before.pieces_count = q.pieces_count
                    AND (row_before.valid_from, row_before.valid_from_tracked_at) < (q.solve_date, q.tracked_at)
                ORDER BY row_before.valid_from DESC, row_before.valid_from_tracked_at DESC, row_before.qualifying_count DESC
                LIMIT 1
            ) h ON true
        ", $params, $types);

        $without = [];

        foreach ($rows as $row) {
            if ($row['pace_baseline_seconds'] === null) {
                $without[$row['player_id']] = true;
            }
        }

        // One first attempt per solver: a solver with a row before it needs nothing else of theirs
        foreach ($rows as $row) {
            if ($row['pace_baseline_seconds'] !== null && !isset($without[$row['player_id']])) {
                $timeline->add($row['player_id'], (int) $row['pieces_count'], (float) $row['pace_solved_ts'], (float) $row['pace_tracked_ts'], (int) $row['pace_baseline_seconds']);
            }
        }

        $this->addRowsOfPlayers($timeline, array_keys($without));

        return [$rows, $timeline];
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
            /** @var array{player_id: string, pieces_count: int|string, solved_ts: float|int|string, tracked_ts: float|int|string, baseline_seconds: int|string} $row */
            $timeline->add($row['player_id'], (int) $row['pieces_count'], (float) $row['solved_ts'], (float) $row['tracked_ts'], (int) $row['baseline_seconds']);
        }
    }
}
