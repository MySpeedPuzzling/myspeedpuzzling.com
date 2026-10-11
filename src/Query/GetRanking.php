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
use Symfony\Contracts\Service\ResetInterface;

/**
 * The player's rank on each puzzle's solo leaderboard - the same pool the puzzle page ranks (PuzzleTimes): best
 * non-suspicious time per player, hidden players left out, private players only when the viewer may see them
 * (and the ranked player themselves).
 */
final class GetRanking implements ResetInterface
{
    /** @var array<string, array<string, PlayerRanking>> */
    private array $allForPlayerCache = [];

    /** @var array<string, null|PlayerRanking> */
    private array $ofPuzzleForPlayerCache = [];

    public function __construct(
        private readonly Connection $database,
        private readonly ClockInterface $clock,
        private readonly HiddenPlayers $hiddenPlayers,
        private readonly PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @return array<string, PlayerRanking>
     */
    public function allForPlayer(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        return $this->allForPlayerCache[$playerId] ??= $this->rankings($playerId, null);
    }

    /**
     * The same ranks as allForPlayer(), only for these puzzles - the rows a list shows (the profile renders 50 of up to
     * 2,000+ puzzles): 89 -> a few ms on the heaviest profile. Puzzles without a ranked solo time are absent.
     *
     * @param list<string> $puzzleIds
     * @throws PlayerNotFound
     * @return array<string, PlayerRanking>
     */
    public function forPuzzles(string $playerId, array $puzzleIds): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        if ($puzzleIds === []) {
            return [];
        }

        if (isset($this->allForPlayerCache[$playerId])) {
            return array_intersect_key($this->allForPlayerCache[$playerId], array_flip($puzzleIds));
        }

        return $this->rankings($playerId, array_values(array_unique($puzzleIds)));
    }

    /**
     * @param null|list<string> $puzzleIds null = every puzzle the player solved
     * @return array<string, PlayerRanking>
     */
    private function rankings(string $playerId, null|array $puzzleIds): array
    {
        $onlyPuzzles = $puzzleIds === null ? '' : 'AND pst.puzzle_id IN (:puzzleIds)';
        $notHidden = $this->hiddenPlayers->sqlExclude('pl.id');
        $playerNotHidden = $this->hiddenPlayers->sqlExclude('pst.player_id');
        $isPublic = $this->privateProfileAccess->sqlIsPublic('pl');

        // RANK() without a window over everybody: a rank is 1 + the players whose best time is faster, ties share it.
        // Every other player's best per puzzle comes from custom_pst_solo_board (index only, docs/database-indexes.md);
        // that index holds times above 0 s - a 0 s time is no time (the web form stores it as none, the Times boards
        // leave it out too; only the API could store one, and none exists). Heaviest profile (2,159 puzzles, a third of
        // all solo results): 200-300 -> 89 ms; a 100-puzzle profile: 60 -> 15 ms.
        $query = <<<SQL
WITH PlayerBest AS MATERIALIZED (
    -- The ranked player's own best time per puzzle; a player the viewer hides ranks nowhere
    SELECT
        pst.player_id,
        pst.puzzle_id,
        MIN(pst.seconds_to_solve) AS best_time
    FROM
        puzzle_solving_time pst
    WHERE
        pst.player_id = :playerId
        AND pst.puzzling_type = 'solo'
        AND pst.seconds_to_solve IS NOT NULL
        AND pst.suspicious = false
        {$onlyPuzzles}
        {$playerNotHidden}
    GROUP BY
        pst.player_id, pst.puzzle_id
),
BestTimes AS (
    -- Everybody's best time on those puzzles: the puzzle page's pool
    SELECT
        pst.puzzle_id,
        pst.player_id,
        MIN(pst.seconds_to_solve) AS best_time
    FROM
        PlayerBest pb
        INNER JOIN puzzle_solving_time pst ON pst.puzzle_id = pb.puzzle_id
        INNER JOIN player pl ON pl.id = pst.player_id
    WHERE
        pst.puzzling_type = 'solo'
        AND pst.seconds_to_solve > 0
        AND pst.suspicious = false
        AND ({$isPublic} OR pl.id = :playerId)
        {$notHidden}
    GROUP BY
        pst.puzzle_id, pst.player_id
),
Ranks AS (
    SELECT
        pb.player_id,
        pb.puzzle_id,
        pb.best_time,
        1 + COUNT(*) FILTER (WHERE bt.best_time < pb.best_time) AS rank,
        COUNT(*) AS total_players
    FROM
        PlayerBest pb
        INNER JOIN BestTimes bt ON bt.puzzle_id = pb.puzzle_id
    GROUP BY
        pb.player_id, pb.puzzle_id, pb.best_time
)
SELECT
    r.player_id,
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
