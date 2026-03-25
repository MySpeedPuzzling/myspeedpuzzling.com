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
}
