<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\LeaderboardSlice;
use SpeedPuzzling\Web\Results\PlayerRatingEntry;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Value\LeaderboardPosition;

readonly final class GetPlayerRatingRanking
{
    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
    ) {
    }

    /**
     * One board of the MSP Rating ladder in one statement: the top $limit rows of the list on screen and the viewer's own
     * row with $above rows above it and $below rows below it (Value\LeaderboardWindow::rowsToLoad()), plus the viewer's
     * position line.
     *
     * The list is the ladder (sqlOnLadder()) narrowed by the country and the favorites filter, and a rank always refers
     * to it: "#12" in Czechia is the 12th Czech, not the 12th in the world (design-spec §4, P9). Equal ratings share the
     * place (RANK()), the order stays stable by the player id. A search only finds rows in that list - the rows keep the
     * list's ranks and the position line stays the list's.
     *
     * @return LeaderboardSlice<PlayerRatingEntry>
     */
    public function window(
        int $piecesCount,
        int $limit,
        int $above = 0,
        int $below = 0,
        null|string $viewerPlayerId = null,
        null|string $country = null,
        null|string $searchTerm = null,
        null|string $favoriteOfPlayerId = null,
    ): LeaderboardSlice {
        $params = [
            'piecesCount' => $piecesCount,
            'limit' => max(1, $limit),
            'above' => max(0, $above),
            'below' => max(0, $below),
            'viewerPlayerId' => $viewerPlayerId,
            'milestones' => '{' . implode(',', LeaderboardPosition::RANK_MILESTONES) . '}',
        ];

        $listFilter = '';

        if ($country !== null) {
            $listFilter .= ' AND p.country = :country';
            $params['country'] = $country;
        }

        if ($favoriteOfPlayerId !== null) {
            $listFilter .= ' AND (pe.player_id = :favoriteOfPlayerId OR pe.player_id IN (SELECT fav_id::uuid FROM player fp, json_array_elements_text(fp.favorite_players) AS fav_id WHERE fp.id = :favoriteOfPlayerId))';
            $params['favoriteOfPlayerId'] = $favoriteOfPlayerId;
        }

        $searchFilter = '';

        if ($searchTerm !== null && $searchTerm !== '') {
            $searchFilter = ' AND (board.player_name ILIKE :searchPattern OR board.player_code ILIKE :searchPattern)';
            $params['searchPattern'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $searchTerm) . '%';
        }

        $onLadder = $this->sqlOnLadder('p');

        // The anchor row keeps the viewer's line when no row of the list is shown (a search without a match)
        $query = <<<SQL
WITH board AS (
    SELECT
        pe.player_id,
        p.name AS player_name,
        p.code AS player_code,
        p.country AS player_country,
        p.avatar AS player_avatar,
        pe.elo_rating,
        ps.skill_tier,
        RANK() OVER (ORDER BY pe.elo_rating DESC) AS rank,
        ROW_NUMBER() OVER (ORDER BY pe.elo_rating DESC, pe.player_id) AS board_position
    FROM player_elo pe
    INNER JOIN player p ON p.id = pe.player_id
    LEFT JOIN player_skill ps ON ps.player_id = pe.player_id AND ps.pieces_count = pe.pieces_count
    WHERE pe.pieces_count = :piecesCount
        AND {$onLadder}
        {$listFilter}
),
listed AS (
    SELECT
        board.*,
        ROW_NUMBER() OVER (ORDER BY board.board_position) AS position,
        COUNT(*) OVER () AS total
    FROM board
    WHERE TRUE{$searchFilter}
),
own AS (
    SELECT
        board.rank AS own_rank,
        ROUND(board.elo_rating * 1000)::int AS own_value,
        (SELECT COUNT(*) FROM board) AS board_total,
        (SELECT COUNT(*) FROM board behind WHERE behind.elo_rating < board.elo_rating) AS rows_behind,
        (SELECT json_object_agg(m.board_position, ROUND(m.elo_rating * 1000)::int) FROM board m WHERE m.board_position = ANY(CAST(:milestones AS int[]))) AS milestone_values,
        (SELECT listed.position FROM listed WHERE listed.player_id = board.player_id) AS own_position
    FROM board
    WHERE board.player_id = CAST(:viewerPlayerId AS UUID)
)
SELECT listed.*, own.*
FROM (SELECT 1) AS anchor
LEFT JOIN own ON TRUE
LEFT JOIN listed ON listed.position <= :limit
    OR listed.position BETWEEN own.own_position - :above AND own.own_position + :below
ORDER BY listed.position
SQL;

        /** @var list<array{player_id: null|string, player_name: null|string, player_code: string, player_country: null|string, player_avatar: null|string, elo_rating: float|string, skill_tier: null|int|string, rank: int|string, position: null|int|string, total: null|int|string, own_rank: null|int|string, own_value: null|int|string, board_total: null|int|string, rows_behind: null|int|string, milestone_values: null|string, own_position: null|int|string}> $rows */
        $rows = $this->database->executeQuery($query, $params)->fetchAllAssociative();

        $entries = [];
        $ranks = [];
        $positions = [];
        $total = 0;

        foreach ($rows as $row) {
            $playerId = $row['player_id'];
            $position = $row['position'];

            if ($playerId === null || $position === null) {
                continue;
            }

            $entry = PlayerRatingEntry::fromDatabaseRow([
                'player_id' => $playerId,
                'player_name' => $row['player_name'],
                'player_code' => $row['player_code'],
                'player_country' => $row['player_country'],
                'player_avatar' => $row['player_avatar'],
                'elo_rating' => $row['elo_rating'],
                'skill_tier' => $row['skill_tier'],
                'rank' => $row['rank'],
            ]);
            $entries[] = $entry;
            $ranks[] = $entry->rank;
            $positions[] = (int) $position - 1;
            $total = (int) $row['total'];
        }

        $first = $rows[0] ?? null;
        $viewerPosition = null;
        $ownPosition = null;

        if ($first !== null && $first['own_rank'] !== null) {
            /** @var array<int|string, int> $milestoneValues */
            $milestoneValues = $first['milestone_values'] !== null ? json_decode($first['milestone_values'], true) : [];

            $viewerPosition = LeaderboardPosition::describe(
                rank: (int) $first['own_rank'],
                total: (int) $first['board_total'],
                ownValue: (int) $first['own_value'],
                milestoneValues: array_combine(array_map('intval', array_keys($milestoneValues)), array_values($milestoneValues)),
                higherIsBetter: true,
                rowsBehind: (int) $first['rows_behind'],
            );
            $ownPosition = $first['own_position'] !== null ? (int) $first['own_position'] - 1 : null;
        }

        return new LeaderboardSlice($entries, $ranks, $positions, $total, $ownPosition, $viewerPosition);
    }

    /**
     * The top of the list (window() without a viewer) - kept for the callers that only need the first rows
     *
     * @return list<PlayerRatingEntry>
     */
    public function ranking(int $piecesCount, int $limit = 50, null|string $country = null, null|string $searchTerm = null, null|string $favoriteOfPlayerId = null): array
    {
        return $this->window($piecesCount, $limit, country: $country, searchTerm: $searchTerm, favoriteOfPlayerId: $favoriteOfPlayerId)->rows;
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
        $rank = $this->sqlRankOf('pe');
        $totalPool = $this->sqlOnLadder('p3');

        $query = <<<SQL
SELECT
    pe.pieces_count,
    pe.elo_rating,
    {$rank} AS rank,
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
     * "#rank" of one player_elo row (RANK()'s: one more than the players on the ladder rated higher) - for
     * allForPlayer() and the player header's MSP Rating chip, which reads it from the profile row
     * (GetPlayerProfile::byId()) so both show the same number.
     */
    public function sqlRankOf(string $eloAlias): string
    {
        $rankPool = $this->sqlOnLadder('rank_p');

        return "(1 + (SELECT COUNT(*) FROM player_elo rank_pe INNER JOIN player rank_p ON rank_p.id = rank_pe.player_id WHERE rank_pe.pieces_count = {$eloAlias}.pieces_count AND rank_pe.elo_rating > {$eloAlias}.elo_rating AND {$rankPool}))";
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
