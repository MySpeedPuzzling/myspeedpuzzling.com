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
    public function ranking(int $piecesCount, int $limit = 50, int $offset = 0): array
    {
        $query = <<<SQL
SELECT
    pe.player_id,
    p.name AS player_name,
    p.code AS player_code,
    p.country AS player_country,
    p.avatar AS player_avatar,
    pe.elo_rating,
    RANK() OVER (ORDER BY pe.elo_rating DESC) AS rank
FROM player_elo pe
INNER JOIN player p ON p.id = pe.player_id
WHERE pe.pieces_count = :piecesCount
    AND p.is_private = false
ORDER BY pe.elo_rating DESC
LIMIT :limit OFFSET :offset
SQL;

        /** @var list<array{player_id: string, player_name: null|string, player_code: string, player_country: null|string, player_avatar: null|string, elo_rating: float|string, rank: int|string}> $rows */
        $rows = $this->database->executeQuery($query, [
            'piecesCount' => $piecesCount,
            'limit' => $limit,
            'offset' => $offset,
        ])->fetchAllAssociative();

        return array_map(
            static fn (array $row): PlayerEloEntry => PlayerEloEntry::fromDatabaseRow($row),
            $rows,
        );
    }

    public function playerPosition(string $playerId, int $piecesCount): null|int
    {
        $query = <<<SQL
SELECT rank FROM (
    SELECT
        pe.player_id,
        RANK() OVER (ORDER BY pe.elo_rating DESC) AS rank
    FROM player_elo pe
    INNER JOIN player p ON p.id = pe.player_id
    WHERE pe.pieces_count = :piecesCount
        AND p.is_private = false
) ranked
WHERE ranked.player_id = :playerId
SQL;

        /** @var false|array{rank: int|string} $row */
        $row = $this->database->executeQuery($query, [
            'playerId' => $playerId,
            'piecesCount' => $piecesCount,
        ])->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return (int) $row['rank'];
    }

    public function totalCount(int $piecesCount): int
    {
        /** @var int|string $count */
        $count = $this->database->executeQuery("
            SELECT COUNT(*)
            FROM player_elo pe
            INNER JOIN player p ON p.id = pe.player_id
            WHERE pe.pieces_count = :piecesCount
                AND p.is_private = false
        ", [
            'piecesCount' => $piecesCount,
        ])->fetchOne();

        return (int) $count;
    }

    /**
     * Get all ELO ratings for a specific player across piece counts.
     *
     * @return array<int, array{elo_rating: float, rank: int, total: int}>
     */
    public function allForPlayer(string $playerId): array
    {
        $query = <<<SQL
SELECT
    pe.pieces_count,
    pe.elo_rating,
    (SELECT COUNT(*) FROM player_elo pe2 INNER JOIN player p2 ON p2.id = pe2.player_id WHERE pe2.pieces_count = pe.pieces_count AND p2.is_private = false AND pe2.elo_rating >= pe.elo_rating) AS rank,
    (SELECT COUNT(*) FROM player_elo pe3 INNER JOIN player p3 ON p3.id = pe3.player_id WHERE pe3.pieces_count = pe.pieces_count AND p3.is_private = false) AS total
FROM player_elo pe
WHERE pe.player_id = :playerId
ORDER BY pe.pieces_count ASC
SQL;

        /** @var list<array{pieces_count: int|string, elo_rating: float|string, rank: int|string, total: int|string}> $rows */
        $rows = $this->database->executeQuery($query, [
            'playerId' => $playerId,
        ])->fetchAllAssociative();

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row['pieces_count']] = [
                'elo_rating' => (float) $row['elo_rating'],
                'rank' => (int) $row['rank'],
                'total' => (int) $row['total'],
            ];
        }

        return $result;
    }
}
