<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Results\PlayerRanking;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The player's rank on each puzzle's solo leaderboard - the same pool the puzzle page ranks (PuzzleTimes): best
 * non-suspicious time per player, hidden players left out, private players only when the viewer may see them
 * (and the ranked player themselves). With $firstTries the board of first tries instead: everybody's first solo attempt,
 * the ranked player's included - the profile's "first tries only" filter; with $unboxed the board of unboxed times
 * (everybody's best unboxed time), with both the first tries that were unboxed (docs/features/profile-results-placing.md).
 */
final class GetRanking implements ResetInterface
{
    /**
     * How long allForPlayerCached() keeps a player's ranks: other players' new times show up within it, the player's own
     * changes at once (they change the fingerprint)
     */
    public const int CACHE_TTL_SECONDS = 120;

    /** @var array<string, array<string, PlayerRanking>> */
    private array $allForPlayerCache = [];

    /** @var array<string, null|PlayerRanking> */
    private array $ofPuzzleForPlayerCache = [];

    public function __construct(
        private readonly Connection $database,
        private readonly ClockInterface $clock,
        private readonly HiddenPlayers $hiddenPlayers,
        private readonly PrivateProfileAccess $privateProfileAccess,
        private readonly CacheInterface $playerRankingsCache,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @return array<string, PlayerRanking>
     */
    public function allForPlayer(string $playerId, bool $firstTries = false, bool $unboxed = false): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        return $this->allForPlayerCache[self::requestCacheKey($playerId, $firstTries, $unboxed)] ??= $this->rankings($playerId, null, $firstTries, $unboxed);
    }

    /**
     * allForPlayer() kept for CACHE_TTL_SECONDS: the profile's placing sorts and filter need every rank, and each Live
     * re-render (a chip, "Show more") would recount every board - ~100-300 ms on the heaviest profiles. Keyed by the
     * viewer's pool (blocks, allow list) and a fingerprint of the player's own solo results, which the caller has loaded
     * anyway. Fills the request's copy too, so forPuzzles() reads from it.
     *
     * @throws PlayerNotFound
     * @return array<string, PlayerRanking>
     */
    public function allForPlayerCached(string $playerId, string $ownResultsFingerprint, bool $firstTries = false, bool $unboxed = false): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $requestCacheKey = self::requestCacheKey($playerId, $firstTries, $unboxed);

        if (isset($this->allForPlayerCache[$requestCacheKey])) {
            return $this->allForPlayerCache[$requestCacheKey];
        }

        $hidden = $this->hiddenPlayers->ids();
        $revealed = $this->privateProfileAccess->revealedIds();
        sort($hidden);
        sort($revealed);

        $key = 'ranks_' . sha1(implode('|', [
            strtolower($playerId),
            $firstTries ? 'first-tries' : 'all',
            $unboxed ? 'unboxed' : 'all',
            $ownResultsFingerprint,
            implode(',', $hidden),
            implode(',', $revealed),
        ]));

        /** @var array<string, PlayerRanking> $ranking */
        $ranking = $this->playerRankingsCache->get($key, function (ItemInterface $item) use ($playerId, $firstTries, $unboxed): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            return $this->rankings($playerId, null, $firstTries, $unboxed);
        });

        return $this->allForPlayerCache[$requestCacheKey] = $ranking;
    }

    /**
     * The same ranks as allForPlayer(), only for these puzzles - the rows a list shows (the profile renders 50 of up to
     * 2,000+ puzzles): 89 -> a few ms on the heaviest profile. Puzzles without a ranked solo time are absent.
     *
     * @param list<string> $puzzleIds
     * @throws PlayerNotFound
     * @return array<string, PlayerRanking>
     */
    public function forPuzzles(string $playerId, array $puzzleIds, bool $firstTries = false, bool $unboxed = false): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        if ($puzzleIds === []) {
            return [];
        }

        $requestCacheKey = self::requestCacheKey($playerId, $firstTries, $unboxed);

        if (isset($this->allForPlayerCache[$requestCacheKey])) {
            return array_intersect_key($this->allForPlayerCache[$requestCacheKey], array_flip($puzzleIds));
        }

        return $this->rankings($playerId, array_values(array_unique($puzzleIds)), $firstTries, $unboxed);
    }

    private static function requestCacheKey(string $playerId, bool $firstTries, bool $unboxed): string
    {
        return $playerId . ($firstTries ? '_first_tries' : '') . ($unboxed ? '_unboxed' : '');
    }

    /**
     * @param null|list<string> $puzzleIds null = every puzzle the player solved
     * @return array<string, PlayerRanking>
     */
    private function rankings(string $playerId, null|array $puzzleIds, bool $firstTries, bool $unboxed): array
    {
        $onlyPuzzles = $puzzleIds === null ? '' : 'AND pst.puzzle_id IN (:puzzleIds)';
        // The first tries board: one attempt per player (first-try integrity), a subset of each full board
        $onlyFirstTries = $firstTries ? 'AND pst.first_attempt = true' : '';
        // The unboxed board: everybody's unboxed times, a subset of each full board too
        $onlyUnboxed = $unboxed ? 'AND pst.unboxed = true' : '';
        $playerNotHidden = $this->hiddenPlayers->sqlExclude('pst.player_id');
        $isPublic = $this->privateProfileAccess->sqlIsPublic('pl');

        // A rank is 1 + the players with a time faster than the ranked player's best (= whose best is faster), ties
        // share it; the board's size is its distinct players. Counted per puzzle from custom_pst_solo_board, keyed
        // (puzzle_id, player_id): each board comes sorted by player, so no aggregate over every board at once and no
        // sort (docs/database-indexes.md). That index holds times above 0 s - a 0 s time is no time (the web form stores
        // it as none, the Times boards leave it out too). Private players the viewer may not see are a short NOT IN list
        // (hashed once), the ranked player always counts. Heaviest profile (2,159 puzzles, 87k board entries) on production
        // 2026-10-26: 240-300 ms as one aggregate over all the boards, 140-155 ms per puzzle on the old index.
        $query = <<<SQL
WITH PlayerBest AS MATERIALIZED (
    -- The ranked player's own best time per puzzle; a player the viewer hides ranks nowhere
    SELECT
        pst.puzzle_id,
        MIN(pst.seconds_to_solve) AS best_time
    FROM
        puzzle_solving_time pst
    WHERE
        pst.player_id = :playerId
        AND pst.puzzling_type = 'solo'
        AND pst.seconds_to_solve IS NOT NULL
        AND pst.suspicious = false
        {$onlyFirstTries}
        {$onlyUnboxed}
        {$onlyPuzzles}
        {$playerNotHidden}
    GROUP BY
        pst.puzzle_id
),
Ranks AS (
    -- Everybody on each of those puzzles' boards: the puzzle page's pool
    SELECT
        pb.puzzle_id,
        pb.best_time,
        board.rank,
        board.total_players
    FROM
        PlayerBest pb
        CROSS JOIN LATERAL (
            SELECT
                1 + COUNT(DISTINCT pst.player_id) FILTER (WHERE pst.seconds_to_solve < pb.best_time) AS rank,
                COUNT(DISTINCT pst.player_id) AS total_players
            FROM
                puzzle_solving_time pst
            WHERE
                pst.puzzle_id = pb.puzzle_id
                AND pst.puzzling_type = 'solo'
                AND pst.seconds_to_solve > 0
                AND pst.suspicious = false
                {$onlyFirstTries}
                {$onlyUnboxed}
                AND pst.player_id NOT IN (SELECT pl.id FROM player pl WHERE NOT ({$isPublic}) AND pl.id <> :playerId)
                {$playerNotHidden}
        ) board
    WHERE
        -- Only a 0 s best of the ranked player leaves the board empty: such a puzzle ranks nowhere, as before
        board.total_players > 0
)
SELECT
    CAST(:playerId AS UUID) AS player_id,
    r.rank,
    r.total_players,
    r.best_time AS time,
    p.id AS puzzle_id,
    p.name AS puzzle_name,
    p.pieces_count,
    CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image END AS puzzle_image,
    CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image_ratio END AS puzzle_image_ratio,
    m.name AS manufacturer_name
FROM
    Ranks r
    INNER JOIN puzzle p ON p.id = r.puzzle_id
    INNER JOIN manufacturer m ON m.id = p.manufacturer_id
ORDER BY
    r.rank, p.id
SQL;

        $parameters = [
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            'playerId' => $playerId,
        ];
        $types = [];

        if ($puzzleIds !== null) {
            $parameters['puzzleIds'] = $puzzleIds;
            $types['puzzleIds'] = ArrayParameterType::STRING;
        }

        $data = $this->database
            ->executeQuery($query, $parameters, $types)
            ->fetchAllAssociative();

        $ranking = [];

        foreach ($data as $row) {
            /**
             * @var array{
             *     player_id: string,
             *     rank: int,
             *     total_players: int,
             *     time: int,
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     pieces_count: int,
             *     puzzle_image: null|string,
             *     puzzle_image_ratio: null|string,
             *     manufacturer_name: string,
             * } $row
             */

            $ranking[$row['puzzle_id']] = PlayerRanking::fromDatabaseRow($row);
        }

        return $ranking;
    }

    /**
     * @throws PlayerNotFound
     * @throws PuzzleNotFound
     */
    public function ofPuzzleForPlayer(string $puzzleId, string $playerId): null|PlayerRanking
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        if (Uuid::isValid($puzzleId) === false) {
            throw new PuzzleNotFound();
        }

        $cacheKey = $puzzleId . '_' . $playerId;

        if (isset($this->ofPuzzleForPlayerCache[$cacheKey])) {
            return $this->ofPuzzleForPlayerCache[$cacheKey];
        }

        $notHidden = $this->hiddenPlayers->sqlExclude('pl.id');
        $isPublic = $this->privateProfileAccess->sqlIsPublic('pl');

        $query = <<<SQL
WITH BestTimes AS (
    SELECT
        pst.puzzle_id,
        pst.player_id,
        MIN(pst.seconds_to_solve) as best_time
    FROM
        puzzle_solving_time pst
        INNER JOIN player pl ON pl.id = pst.player_id
    WHERE pst.puzzling_type = 'solo'
        AND pst.puzzle_id = :puzzleId
        AND pst.seconds_to_solve IS NOT NULL
        AND pst.suspicious = false
        AND ({$isPublic} OR pl.id = :playerId)
        {$notHidden}
    GROUP BY
        pst.puzzle_id, pst.player_id
),
RankedTimes AS (
    SELECT
        puzzle_id,
        player_id,
        best_time,
        RANK() OVER (PARTITION BY puzzle_id ORDER BY best_time ASC) as rank,
        COUNT(player_id) OVER (PARTITION BY puzzle_id) as total_players
    FROM
        BestTimes
)
SELECT
    player_id,
    rank,
    total_players,
    best_time AS time,
    puzzle.id AS puzzle_id,
    puzzle.name AS puzzle_name,
    puzzle.pieces_count,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image END AS puzzle_image,
    CASE WHEN puzzle.hide_image_until IS NOT NULL AND puzzle.hide_image_until > :now::timestamp THEN NULL ELSE puzzle.image_ratio END AS puzzle_image_ratio,
    manufacturer.name AS manufacturer_name
FROM
    RankedTimes
INNER JOIN puzzle ON puzzle.id = RankedTimes.puzzle_id
INNER JOIN manufacturer ON manufacturer.id = puzzle.manufacturer_id
WHERE
    player_id = :playerId
ORDER BY rank
SQL;

        /**
         * @var false|array{
         *     player_id: string,
         *     rank: int,
         *     total_players: int,
         *     time: int,
         *     puzzle_id: string,
         *     puzzle_name: string,
         *     pieces_count: int,
         *     puzzle_image: null|string,
         *     puzzle_image_ratio: null|string,
         *     manufacturer_name: string,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                'playerId' => $playerId,
                'puzzleId' => $puzzleId,
            ])
            ->fetchAssociative();

        if ($row === false) {
            $this->ofPuzzleForPlayerCache[$cacheKey] = null;

            return null;
        }

        $ranking = PlayerRanking::fromDatabaseRow($row);
        $this->ofPuzzleForPlayerCache[$cacheKey] = $ranking;

        return $ranking;
    }

    public function reset(): void
    {
        $this->allForPlayerCache = [];
        $this->ofPuzzleForPlayerCache = [];
    }
}
