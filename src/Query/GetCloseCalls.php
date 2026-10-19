<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\CloseCallCandidate;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\LeaderboardPosition;

/**
 * Close calls of the Insights page (docs/features/insights/computation.md "Core sections" §3): for every puzzle the
 * player has a solo time on, their place on its solo leaderboard and the times of the places above them.
 *
 * It reads only times faster than the player's best: a place is 1 + the players whose best time is faster, and the
 * time of place N above you is the N-th fastest of their bests - so a slow player on a popular puzzle costs no more
 * than the players ahead. The pool is GetRanking's (the puzzle page's): best non-suspicious solo time per player, the
 * players the viewer hides left out, private players only when the viewer may see them - the places equal
 * GetRanking::allForPlayer() (pinned by GetCloseCallsTest). The players left out are one MATERIALIZED CTE: inlined
 * into every lateral probe, the plan crossed the JIT threshold (+290 ms on the heaviest player).
 *
 * Self-only (the viewer is the owner): other players appear as places, never by name. Secret puzzles are left out.
 */
readonly final class GetCloseCalls
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @return array<string, CloseCallCandidate> keyed by puzzle id
     */
    public function forPlayer(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $isPublic = $this->privateProfileAccess->sqlIsPublic('pl');
        $notHidden = $this->hiddenPlayers->sqlExclude('pl.id');

        $placeColumns = implode(",\n        ", array_map(
            static fn (int $position): string => "MAX(y.best_time) FILTER (WHERE y.position = {$position}) AS place_{$position}",
            LeaderboardPosition::RANK_MILESTONES,
        ));
        $selectedPlaces = implode(",\n    ", array_map(
            static fn (int $position): string => "ahead.place_{$position}",
            LeaderboardPosition::RANK_MILESTONES,
        ));

        $query = <<<SQL
WITH left_out AS MATERIALIZED (
    -- Everybody outside GetRanking's pool: ({$isPublic} OR the player themselves) and not hidden by the viewer
    SELECT pl.id
    FROM player pl
    WHERE NOT (({$isPublic} OR pl.id = :playerId){$notHidden})
),
own_best AS MATERIALIZED (
    SELECT pst.puzzle_id, MIN(pst.seconds_to_solve) AS best_time
    FROM puzzle_solving_time pst
    INNER JOIN puzzle p ON p.id = pst.puzzle_id
    WHERE pst.player_id = :playerId
        AND pst.puzzling_type = 'solo'
        AND pst.seconds_to_solve IS NOT NULL
        AND pst.suspicious = false
        AND (p.hide_until IS NULL OR p.hide_until <= :now)
        AND (p.hide_image_until IS NULL OR p.hide_image_until <= :now)
    GROUP BY pst.puzzle_id
)
SELECT
    ob.puzzle_id,
    ob.best_time,
    1 + COALESCE(ahead.faster, 0) AS rank,
    ahead.next_place,
    {$selectedPlaces}
FROM own_best ob
LEFT JOIN LATERAL (
    SELECT
        COUNT(*) AS faster,
        MAX(y.best_time) AS next_place,
        {$placeColumns}
    FROM (
        SELECT x.best_time, ROW_NUMBER() OVER (ORDER BY x.best_time) AS position
        FROM (
            -- custom_pst_solo_board: index only
            SELECT MIN(o.seconds_to_solve) AS best_time
            FROM puzzle_solving_time o
            WHERE o.puzzle_id = ob.puzzle_id
                AND o.puzzling_type = 'solo'
                AND o.suspicious = false
                AND o.seconds_to_solve > 0
                AND o.seconds_to_solve < ob.best_time
                AND o.player_id NOT IN (SELECT id FROM left_out)
            GROUP BY o.player_id
        ) x
    ) y
) ahead ON true
SQL;

        $rows = $this->database->executeQuery($query, [
            'playerId' => $playerId,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ])->fetchAllAssociative();

        $candidates = [];

        foreach ($rows as $row) {
            assert(is_string($row['puzzle_id']));
            assert(is_int($row['best_time']) || is_string($row['best_time']));
            assert(is_int($row['rank']) || is_string($row['rank']));

            $rank = (int) $row['rank'];
            $milestones = [];

            foreach (LeaderboardPosition::RANK_MILESTONES as $position) {
                $value = $row['place_' . $position] ?? null;

                if ($position < $rank && (is_int($value) || is_string($value))) {
                    $milestones[$position] = (int) $value;
                }
            }

            $nextPlace = $row['next_place'] ?? null;

            $candidates[$row['puzzle_id']] = new CloseCallCandidate(
                puzzleId: $row['puzzle_id'],
                bestSeconds: (int) $row['best_time'],
                rank: $rank,
                nextPlaceSeconds: is_int($nextPlace) || is_string($nextPlace) ? (int) $nextPlace : null,
                milestoneSeconds: $milestones,
            );
        }

        return $candidates;
    }
}
