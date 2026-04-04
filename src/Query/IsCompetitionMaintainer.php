<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class IsCompetitionMaintainer
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function check(string $competitionId, string $playerId): bool
    {
        $query = <<<SQL
SELECT 1 FROM (
    SELECT added_by_player_id AS player_id FROM competition WHERE id = :competitionId AND added_by_player_id = :playerId
    UNION
    SELECT player_id FROM competition_maintainer WHERE competition_id = :competitionId AND player_id = :playerId
    UNION
    SELECT cs.added_by_player_id AS player_id FROM competition_series cs INNER JOIN competition c ON c.series_id = cs.id WHERE c.id = :competitionId AND cs.added_by_player_id = :playerId
    UNION
    SELECT csm.player_id FROM competition_series_maintainer csm INNER JOIN competition c ON c.series_id = csm.competition_series_id WHERE c.id = :competitionId AND csm.player_id = :playerId
) sub
LIMIT 1
SQL;

        $result = $this->database
            ->executeQuery($query, [
                'competitionId' => $competitionId,
                'playerId' => $playerId,
            ])
            ->fetchOne();

        return $result !== false;
    }
}
