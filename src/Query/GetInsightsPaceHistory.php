<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\Insights\InsightsPuzzleRef;
use SpeedPuzzling\Web\Results\Insights\PaceHistoryRow;
use SpeedPuzzling\Web\Results\Insights\PacePoint;

/**
 * The player's own pace history with the solve and puzzle behind every row (improvement curve, forecast) - one
 * statement over the (player_id, pieces_count, valid_from, valid_from_tracked_at) index.
 */
readonly final class GetInsightsPaceHistory
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<PaceHistoryRow> by piece count, then in time order
     */
    public function forPlayer(string $playerId, null|int $piecesCount = null): array
    {
        $puzzleColumns = InsightsPuzzleRef::sqlColumns();
        $piecesCondition = $piecesCount !== null ? 'AND h.pieces_count = :piecesCount' : '';

        $query = <<<SQL
SELECT
    h.pieces_count AS pace_pieces_count,
    h.valid_from,
    h.baseline_seconds,
    h.qualifying_count,
    h.step,
    h.time_id,
    pst.seconds_to_solve,
    {$puzzleColumns}
FROM player_pace_history h
JOIN puzzle_solving_time pst ON pst.id = h.time_id
JOIN puzzle p ON p.id = pst.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
WHERE h.player_id = :playerId
    AND pst.suspicious = false
    {$piecesCondition}
ORDER BY h.pieces_count, h.valid_from, h.valid_from_tracked_at, h.qualifying_count
SQL;

        $parameters = ['playerId' => $playerId, 'now' => $this->clock->now()->format('Y-m-d H:i:s')];

        if ($piecesCount !== null) {
            $parameters['piecesCount'] = $piecesCount;
        }

        /** @var list<array{pace_pieces_count: int|string, valid_from: string, baseline_seconds: int|string, qualifying_count: int|string, step: null|float|string, time_id: string, seconds_to_solve: null|int|string, puzzle_id: string, puzzle_name: string, puzzle_image: null|string, brand_name: null|string, pieces_count: int|string, puzzle_secret: bool|int|string}> $rows */
        $rows = $this->database->fetchAllAssociative($query, $parameters);

        return array_map(static fn (array $row): PaceHistoryRow => new PaceHistoryRow(
            piecesCount: (int) $row['pace_pieces_count'],
            validFrom: new DateTimeImmutable($row['valid_from']),
            baselineSeconds: (int) $row['baseline_seconds'],
            qualifyingCount: (int) $row['qualifying_count'],
            step: $row['step'] !== null ? (float) $row['step'] : null,
            timeId: $row['time_id'],
            solveSeconds: (int) $row['seconds_to_solve'],
            puzzle: InsightsPuzzleRef::fromRow($row),
        ), $rows);
    }

    /**
     * Only the paces at one piece count, no solve or puzzle (the forecast's milestones) - an index range.
     *
     * @return list<PacePoint> in time order
     */
    public function pacePoints(string $playerId, int $piecesCount): array
    {
        /** @var list<array{valid_from: string, qualifying_count: int|string, baseline_seconds: int|string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT valid_from, qualifying_count, baseline_seconds
            FROM player_pace_history
            WHERE player_id = :playerId AND pieces_count = :piecesCount
            ORDER BY valid_from, valid_from_tracked_at, qualifying_count',
            ['playerId' => $playerId, 'piecesCount' => $piecesCount],
        );

        return array_map(static fn (array $row): PacePoint => new PacePoint(
            date: new DateTimeImmutable($row['valid_from']),
            firstTries: (int) $row['qualifying_count'],
            paceSeconds: (int) $row['baseline_seconds'],
        ), $rows);
    }
}
