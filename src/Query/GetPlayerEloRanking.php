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
    public function ranking(int $piecesCount, int $limit = 50, int $offset = 0, null|string $country = null, null|string $searchTerm = null, null|string $favoriteOfPlayerId = null): array
    {
        $params = [
            'piecesCount' => $piecesCount,
            'limit' => $limit,
            'offset' => $offset,
        ];

        $filterClauses = '';

        if ($country !== null) {
            $filterClauses .= ' AND ranked.player_country = :country';
            $params['country'] = $country;
        }

        if ($searchTerm !== null) {
            $filterClauses .= ' AND (ranked.player_name ILIKE :searchPattern OR ranked.player_code ILIKE :searchPattern)';
            $params['searchPattern'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $searchTerm) . '%';
        }

        if ($favoriteOfPlayerId !== null) {
            $filterClauses .= ' AND (ranked.player_id = :favoriteOfPlayerId OR ranked.player_id IN (SELECT fav_id::uuid FROM player, json_array_elements_text(player.favorite_players) AS fav_id WHERE player.id = :favoriteOfPlayerId))';
            $params['favoriteOfPlayerId'] = $favoriteOfPlayerId;
        }

        $query = <<<SQL
SELECT * FROM (
    SELECT
        pe.player_id,
        p.name AS player_name,
        p.code AS player_code,
        p.country AS player_country,
        p.avatar AS player_avatar,
        pe.elo_rating,
        ps.skill_tier,
        RANK() OVER (ORDER BY pe.elo_rating DESC) AS rank
    FROM player_elo pe
    INNER JOIN player p ON p.id = pe.player_id
    LEFT JOIN player_skill ps ON ps.player_id = pe.player_id AND ps.pieces_count = pe.pieces_count
    WHERE pe.pieces_count = :piecesCount
        AND p.is_private = false
        AND p.ranking_opted_out = false
) ranked
WHERE 1=1{$filterClauses}
ORDER BY ranked.elo_rating DESC
LIMIT :limit OFFSET :offset
SQL;

        /** @var list<array{player_id: string, player_name: null|string, player_code: string, player_country: null|string, player_avatar: null|string, elo_rating: float|string, skill_tier: null|int|string, rank: int|string}> $rows */
        $rows = $this->database->executeQuery($query, $params)->fetchAllAssociative();

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
        AND p.ranking_opted_out = false
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

    public function totalCount(int $piecesCount, null|string $country = null, null|string $searchTerm = null, null|string $favoriteOfPlayerId = null): int
    {
        $params = ['piecesCount' => $piecesCount];
        $filterClauses = '';

        if ($country !== null) {
            $filterClauses .= ' AND p.country = :country';
            $params['country'] = $country;
        }

        if ($searchTerm !== null) {
            $filterClauses .= ' AND (p.name ILIKE :searchPattern OR p.code ILIKE :searchPattern)';
            $params['searchPattern'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $searchTerm) . '%';
        }

        if ($favoriteOfPlayerId !== null) {
            $filterClauses .= ' AND (pe.player_id = :favoriteOfPlayerId OR pe.player_id IN (SELECT fav_id::uuid FROM player fp, json_array_elements_text(fp.favorite_players) AS fav_id WHERE fp.id = :favoriteOfPlayerId))';
            $params['favoriteOfPlayerId'] = $favoriteOfPlayerId;
        }

        /** @var int|string $count */
        $count = $this->database->executeQuery("
            SELECT COUNT(*)
            FROM player_elo pe
            INNER JOIN player p ON p.id = pe.player_id
            WHERE pe.pieces_count = :piecesCount
                AND p.is_private = false
                AND p.ranking_opted_out = false
                {$filterClauses}
        ", $params)->fetchOne();

        return (int) $count;
    }

    /**
     * @return list<string>
     */
    public function distinctCountries(int $piecesCount): array
    {
        /** @var list<string> $codes */
        $codes = $this->database->executeQuery("
            SELECT DISTINCT p.country
            FROM player_elo pe
            INNER JOIN player p ON p.id = pe.player_id
            WHERE pe.pieces_count = :piecesCount
                AND p.is_private = false
                AND p.ranking_opted_out = false
                AND p.country IS NOT NULL
            ORDER BY p.country
        ", [
            'piecesCount' => $piecesCount,
        ])->fetchFirstColumn();

        return $codes;
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
