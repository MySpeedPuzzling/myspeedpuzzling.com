<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class GetPlayerIdsForSitemap
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Public (non-private) player profiles with a name for route player_profile - only those with at
     * least one result on them, solo or as a member of a pair / team (what the profile lists). A
     * profile without a single time is an empty page. 60-190 ms on the production copy.
     *
     * @return array<string>
     */
    public function publicWithResults(): array
    {
        $query = <<<SQL
SELECT player.id
FROM player
WHERE player.is_private = false
    AND player.name IS NOT NULL
    AND player.name != ''
    AND (
        EXISTS (
            SELECT 1
            FROM puzzle_solving_time
            WHERE puzzle_solving_time.player_id = player.id
        )
        OR EXISTS (
            SELECT 1
            FROM puzzle_solving_time
            WHERE puzzle_solving_time.team IS NOT NULL
                AND (puzzle_solving_time.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', player.id))
        )
    )
ORDER BY player.id
SQL;

        /** @var array<string> $playerIds */
        $playerIds = $this->database
            ->executeQuery($query)
            ->fetchFirstColumn();

        return $playerIds;
    }
}
