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
     * Just enough rows for these lookups to come out as on the whole timeline: the row valid just
     * before each solve at its own piece count (one index step each), and every row of the solvers
     * without one (they need their other piece counts or their first pace - a few, usually).
     *
     * @param list<array{player_id: string, pieces_count: int, solve_date: string, tracked_at: string}> $lookups
     */
    public function forLookups(array $lookups): PaceTimeline
    {
        $timeline = new PaceTimeline($this->baselineCalculator);

        if ($lookups === []) {
            return $timeline;
        }

        /** @var list<array{player_id: string, pieces_count: int|string, solved_ts: float|int|string, tracked_ts: float|int|string, baseline_seconds: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative('
            SELECT ' . self::COLUMNS . '
            FROM unnest(
                CAST(:players AS uuid[]),
                CAST(:piecesCounts AS integer[]),
                CAST(:solved AS timestamp[]),
                CAST(:tracked AS timestamp[])
            ) AS l (player_id, pieces_count, solved, tracked)
            CROSS JOIN LATERAL (
                SELECT * FROM player_pace_history row_before
                WHERE row_before.player_id = l.player_id
                    AND row_before.pieces_count = l.pieces_count
                    AND (row_before.valid_from, row_before.valid_from_tracked_at) < (l.solved, l.tracked)
                ORDER BY row_before.valid_from DESC, row_before.valid_from_tracked_at DESC, row_before.qualifying_count DESC
                LIMIT 1
            ) h
        ', [
            'players' => self::arrayLiteral(array_column($lookups, 'player_id')),
            'piecesCounts' => self::arrayLiteral(array_column($lookups, 'pieces_count')),
            'solved' => self::arrayLiteral(array_column($lookups, 'solve_date')),
            'tracked' => self::arrayLiteral(array_column($lookups, 'tracked_at')),
        ]);

        $found = [];

        foreach ($rows as $row) {
            $found[$row['player_id']] = true;
        }

        $without = [];

        foreach ($lookups as $lookup) {
            if (!isset($found[$lookup['player_id']])) {
                $without[$lookup['player_id']] = true;
            }
        }

        // Players with a lookup of their own row: only that row, nothing else of theirs is needed
        foreach ($rows as $row) {
            if (!isset($without[$row['player_id']])) {
                $timeline->add($row['player_id'], (int) $row['pieces_count'], (float) $row['solved_ts'], (float) $row['tracked_ts'], (int) $row['baseline_seconds']);
            }
        }

        $this->addRowsOfPlayers($timeline, array_keys($without));

        return $timeline;
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

    /**
     * @param list<int|string> $values
     */
    private static function arrayLiteral(array $values): string
    {
        return '{' . implode(',', array_map(static fn (int|string $value): string => '"' . $value . '"', $values)) . '}';
    }
}
