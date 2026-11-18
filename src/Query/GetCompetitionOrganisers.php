<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * Who organises an event - the people COMPETITION_EDIT grants it to, admins excluded (an admin is in only as one of
 * these): its creator and maintainers, its series' creator and maintainers, its organization's (or its series'
 * organization's) creator and maintainers. The inverse of GetCompetitionPermissions' competition rows, in one statement.
 * Used to tell the organisers that people wait for them (participant-linking.md §9.2, D16).
 */
readonly final class GetCompetitionOrganisers
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<string> player ids, each once, in a stable order
     */
    public function playerIds(string $competitionId): array
    {
        $query = <<<SQL
SELECT player_id::text FROM (
    SELECT c.added_by_player_id AS player_id FROM competition c WHERE c.id = :competitionId
    UNION
    SELECT cm.player_id FROM competition_maintainer cm WHERE cm.competition_id = :competitionId
    UNION
    SELECT cs.added_by_player_id FROM competition c INNER JOIN competition_series cs ON cs.id = c.series_id WHERE c.id = :competitionId
    UNION
    SELECT csm.player_id FROM competition c INNER JOIN competition_series_maintainer csm ON csm.competition_series_id = c.series_id WHERE c.id = :competitionId
    UNION
    SELECT o.added_by_player_id FROM competition c
    LEFT JOIN competition_series cs ON cs.id = c.series_id
    INNER JOIN organization o ON o.id = COALESCE(c.organization_id, cs.organization_id)
    WHERE c.id = :competitionId
    UNION
    SELECT om.player_id FROM competition c
    LEFT JOIN competition_series cs ON cs.id = c.series_id
    INNER JOIN organization_maintainer om ON om.organization_id = COALESCE(c.organization_id, cs.organization_id)
    WHERE c.id = :competitionId
) organisers
WHERE player_id IS NOT NULL
ORDER BY player_id
SQL;

        /** @var list<string> $ids */
        $ids = $this->database->fetchFirstColumn($query, ['competitionId' => $competitionId]);

        return $ids;
    }
}
