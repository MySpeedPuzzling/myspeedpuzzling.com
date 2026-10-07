<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * Everything a participant import plan depends on, as one hash (docs/features/competitions-management/participant-import-preview.md D8):
 * the event's participants (id, name, country, external id, player, active, source), round entries (id, participant,
 * round, team), teams (id, round, name) and rounds (id, name, category), each in id order. Results are not in it -
 * they arrive all day on event day; the plan's results guard covers them.
 */
readonly final class GetParticipantImportStateVersion
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function ofCompetition(string $competitionId): string
    {
        $query = <<<SQL
SELECT encode(sha256(convert_to(concat_ws(E'\n',
    (
        SELECT string_agg(json_build_array(cp.id, cp.name, cp.country, cp.external_id, cp.player_id, cp.deleted_at IS NULL, cp.source)::text, E'\n' ORDER BY cp.id)
        FROM competition_participant cp
        WHERE cp.competition_id = :competitionId
    ),
    '#',
    (
        SELECT string_agg(json_build_array(cpr.id, cpr.participant_id, cpr.round_id, cpr.team_id)::text, E'\n' ORDER BY cpr.id)
        FROM competition_participant_round cpr
        INNER JOIN competition_participant cp ON cp.id = cpr.participant_id
        WHERE cp.competition_id = :competitionId
    ),
    '#',
    (
        SELECT string_agg(json_build_array(ct.id, ct.round_id, ct.name)::text, E'\n' ORDER BY ct.id)
        FROM competition_team ct
        INNER JOIN competition_round cr ON cr.id = ct.round_id
        WHERE cr.competition_id = :competitionId
    ),
    '#',
    (
        SELECT string_agg(json_build_array(cr.id, cr.name, cr.category)::text, E'\n' ORDER BY cr.id)
        FROM competition_round cr
        WHERE cr.competition_id = :competitionId
    )
), 'UTF8')), 'hex')
SQL;

        /** @var string $version */
        $version = $this->database->fetchOne($query, ['competitionId' => $competitionId]);

        return $version;
    }
}
