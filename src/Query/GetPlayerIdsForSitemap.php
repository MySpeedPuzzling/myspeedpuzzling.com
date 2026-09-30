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
     * profile without a single time is an empty page. Group membership comes from puzzling_team_member,
     * not the `team` JSON snapshot - a correlated JSON containment check re-scanned every group time per
     * player and ran for minutes on production (Sentry WEB-CW); this runs in ~110 ms there.
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
            FROM puzzling_team_member
            INNER JOIN puzzle_solving_time ON puzzle_solving_time.puzzling_team_id = puzzling_team_member.team_id
            WHERE puzzling_team_member.player_id = player.id
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
