<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PlayerRatingEntry;
use SpeedPuzzling\Web\Services\HiddenPlayers;

readonly final class GetPlayerRatingRanking
{
    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
    ) {
    }

    /**
     * @return list<PlayerRatingEntry>
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

        $onLadder = $this->sqlOnLadder('p');

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
        AND {$onLadder}
) ranked
WHERE 1=1{$filterClauses}
ORDER BY ranked.elo_rating DESC
LIMIT :limit OFFSET :offset
SQL;

        /** @var list<array{player_id: string, player_name: null|string, player_code: string, player_country: null|string, player_avatar: null|string, elo_rating: float|string, skill_tier: null|int|string, rank: int|string}> $rows */
        $rows = $this->database->executeQuery($query, $params)->fetchAllAssociative();

        return array_map(
            static fn (array $row): PlayerRatingEntry => PlayerRatingEntry::fromDatabaseRow($row),
            $rows,
        );
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

        $onLadder = $this->sqlOnLadder('p');

        /** @var int|string $count */
        $count = $this->database->executeQuery("
            SELECT COUNT(*)
            FROM player_elo pe
            INNER JOIN player p ON p.id = pe.player_id
            WHERE pe.pieces_count = :piecesCount
                AND {$onLadder}
                {$filterClauses}
        ", $params)->fetchOne();

        return (int) $count;
    }

    /**
     * @return list<string>
     */
    public function distinctCountries(int $piecesCount): array
    {
        $onLadder = $this->sqlOnLadder('p');

        /** @var list<string> $codes */
        $codes = $this->database->executeQuery("
            SELECT DISTINCT p.country
            FROM player_elo pe
            INNER JOIN player p ON p.id = pe.player_id
            WHERE pe.pieces_count = :piecesCount
                AND {$onLadder}
                AND p.country IS NOT NULL
            ORDER BY p.country
        ", [
            'piecesCount' => $piecesCount,
        ])->fetchFirstColumn();

        return $codes;
    }

    /**
     * The player's rating, "#rank" and "ranked among total" per piece count - what the profile card, the ladder's
     * own card, the recap and the API show. The pool is the ladder's (sqlOnLadder()) plus the player themselves (a
     * private player still sees their own place), and the rank is RANK()'s: one more than the players rated higher.
     *
     * @return array<int, array{elo_rating: float, rank: int, total: int}>
     */
    public function allForPlayer(string $playerId): array
    {
        $rankPool = $this->sqlOnLadder('p2');
        $totalPool = $this->sqlOnLadder('p3');

        $query = <<<SQL
SELECT
    pe.pieces_count,
    pe.elo_rating,
    1 + (SELECT COUNT(*) FROM player_elo pe2 INNER JOIN player p2 ON p2.id = pe2.player_id WHERE pe2.pieces_count = pe.pieces_count AND pe2.elo_rating > pe.elo_rating AND {$rankPool}) AS rank,
    (SELECT COUNT(*) FROM player_elo pe3 INNER JOIN player p3 ON p3.id = pe3.player_id WHERE pe3.pieces_count = pe.pieces_count AND (({$totalPool}) OR p3.id = :playerId)) AS total
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

    /**
     * Who is on the ladder - the one definition for the ladder rows, their count, the country facet and every
     * "#rank of total" (allForPlayer()). Each place used to spell it out on its own, and the profile card forgot the
     * players who opted out of rankings: the same player was #303 of 1136 there and #300 of 1126 on the ladder.
     * Global rankings stay closed to private players for everybody; players hidden from the viewer close up.
     */
    private function sqlOnLadder(string $playerAlias): string
    {
        return "{$playerAlias}.is_private = false AND {$playerAlias}.ranking_opted_out = false"
            . $this->hiddenPlayers->sqlExclude("{$playerAlias}.id");
    }
}
