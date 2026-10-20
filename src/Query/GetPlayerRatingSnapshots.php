<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The player's own daily rating snapshots (player_rating_snapshot, one row per piece count and day - the day's last
 * values) with a rating - "your rating over time". One statement over the (player_id, snapshot_date) index.
 */
readonly final class GetPlayerRatingSnapshots
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<int> $piecesCounts
     *
     * @return list<array{pieces_count: int, date: DateTimeImmutable, rating: float, place: null|int}> by piece count, then date
     */
    public function forPlayer(string $playerId, array $piecesCounts, null|DateTimeImmutable $from = null, null|DateTimeImmutable $to = null): array
    {
        /** @var list<array{pieces_count: int|string, snapshot_date: string, elo_rating: float|string, elo_rank: null|int|string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT pieces_count, snapshot_date, elo_rating, elo_rank
            FROM player_rating_snapshot
            WHERE player_id = :playerId
                AND pieces_count IN (:piecesCounts)
                AND elo_rating IS NOT NULL
                AND (CAST(:from AS timestamp) IS NULL OR snapshot_date >= CAST(:from AS timestamp))
                AND (CAST(:to AS timestamp) IS NULL OR snapshot_date < CAST(:to AS timestamp))
            ORDER BY pieces_count, snapshot_date',
            [
                'playerId' => $playerId,
                'piecesCounts' => $piecesCounts,
                'from' => $from?->format('Y-m-d H:i:s'),
                'to' => $to?->format('Y-m-d H:i:s'),
            ],
            ['piecesCounts' => ArrayParameterType::INTEGER],
        );

        return array_map(static fn (array $row): array => [
            'pieces_count' => (int) $row['pieces_count'],
            'date' => new DateTimeImmutable($row['snapshot_date']),
            'rating' => (float) $row['elo_rating'],
            'place' => $row['elo_rank'] !== null ? (int) $row['elo_rank'] : null,
        ], $rows);
    }

    /**
     * The first stored day - where `myspeedpuzzling:rerank-rating-snapshots` starts. Null without any snapshot.
     */
    public function firstDay(): null|DateTimeImmutable
    {
        /** @var false|null|string $day */
        $day = $this->database->fetchOne('SELECT MIN(snapshot_date) FROM player_rating_snapshot');

        return is_string($day) ? new DateTimeImmutable($day) : null;
    }
}
