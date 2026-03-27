<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PlayerEloEntry;

readonly final class GetPlayerEloRanking
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<PlayerEloEntry>
     */
    public function ranking(int $piecesCount, string $period, int $limit = 50, int $offset = 0): array
    {
        $query = <<<SQL
SELECT
    pe.player_id,
    p.name AS player_name,
    p.code AS player_code,
    p.country AS player_country,
    p.avatar AS player_avatar,
    pe.elo_rating,
    pe.last_solve_at,
    RANK() OVER (ORDER BY pe.elo_rating DESC) AS rank
FROM player_elo pe
INNER JOIN player p ON p.id = pe.player_id
WHERE pe.pieces_count = :piecesCount
    AND pe.period = :period
    AND p.is_private = false
ORDER BY pe.elo_rating DESC
LIMIT :limit OFFSET :offset
SQL;

        /** @var list<array{player_id: string, player_name: null|string, player_code: string, player_country: null|string, player_avatar: null|string, elo_rating: int|string, rank: int|string, last_solve_at: null|string}> $rows */
        $rows = $this->database->executeQuery($query, [
            'piecesCount' => $piecesCount,
            'period' => $period,
            'limit' => $limit,
            'offset' => $offset,
        ])->fetchAllAssociative();

        return array_map(
            static fn (array $row): PlayerEloEntry => PlayerEloEntry::fromDatabaseRow($row),
            $rows,
        );
    }

    public function playerPosition(string $playerId, int $piecesCount, string $period): null|int
    {
        $query = <<<SQL
SELECT rank FROM (
    SELECT
        pe.player_id,
        RANK() OVER (ORDER BY pe.elo_rating DESC) AS rank
    FROM player_elo pe
    INNER JOIN player p ON p.id = pe.player_id
    WHERE pe.pieces_count = :piecesCount
        AND pe.period = :period
        AND p.is_private = false
) ranked
WHERE ranked.player_id = :playerId
SQL;

        /** @var false|array{rank: int|string} $row */
        $row = $this->database->executeQuery($query, [
            'playerId' => $playerId,
            'piecesCount' => $piecesCount,
            'period' => $period,
        ])->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return (int) $row['rank'];
    }

    public function totalCount(int $piecesCount, string $period): int
    {
        /** @var int|string $count */
        $count = $this->database->executeQuery("
            SELECT COUNT(*)
            FROM player_elo pe
            INNER JOIN player p ON p.id = pe.player_id
            WHERE pe.pieces_count = :piecesCount
                AND pe.period = :period
                AND p.is_private = false
        ", [
            'piecesCount' => $piecesCount,
            'period' => $period,
        ])->fetchOne();

        return (int) $count;
    }

    /**
     * Get piece counts that have enough ranked players to show on the ladder.
     *
     * @return list<array{pieces_count: int, player_count: int}>
     */
    public function availablePieceCounts(string $period, int $minimumPlayers = 50): array
    {
        /** @var list<array{pieces_count: int|string, player_count: int|string}> $rows */
        $rows = $this->database->executeQuery("
            SELECT pe.pieces_count, COUNT(*) AS player_count
            FROM player_elo pe
            INNER JOIN player p ON p.id = pe.player_id
            WHERE pe.period = :period
                AND p.is_private = false
            GROUP BY pe.pieces_count
            HAVING COUNT(*) >= :minimum
            ORDER BY
                CASE WHEN pe.pieces_count = 500 THEN 0 ELSE 1 END,
                COUNT(*) DESC,
                pe.pieces_count ASC
        ", [
            'period' => $period,
            'minimum' => $minimumPlayers,
        ])->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'pieces_count' => (int) $row['pieces_count'],
            'player_count' => (int) $row['player_count'],
        ], $rows);
    }

    /**
     * Get all ELO ratings for a specific player across piece counts for a given period.
     *
     * @return array<int, array{elo_rating: int, rank: int, total: int}>
     */
    public function allForPlayer(string $playerId, string $period): array
    {
        $query = <<<SQL
SELECT
    pe.pieces_count,
    pe.elo_rating,
    (SELECT COUNT(*) FROM player_elo pe2 INNER JOIN player p2 ON p2.id = pe2.player_id WHERE pe2.pieces_count = pe.pieces_count AND pe2.period = :period AND p2.is_private = false AND pe2.elo_rating >= pe.elo_rating) AS rank,
    (SELECT COUNT(*) FROM player_elo pe3 INNER JOIN player p3 ON p3.id = pe3.player_id WHERE pe3.pieces_count = pe.pieces_count AND pe3.period = :period AND p3.is_private = false) AS total
FROM player_elo pe
WHERE pe.player_id = :playerId
    AND pe.period = :period
ORDER BY pe.pieces_count ASC
SQL;

        /** @var list<array{pieces_count: int|string, elo_rating: int|string, rank: int|string, total: int|string}> $rows */
        $rows = $this->database->executeQuery($query, [
            'playerId' => $playerId,
            'period' => $period,
        ])->fetchAllAssociative();

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row['pieces_count']] = [
                'elo_rating' => (int) $row['elo_rating'],
                'rank' => (int) $row['rank'],
                'total' => (int) $row['total'],
            ];
        }

        return $result;
    }
}
