<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * Everything a participant import plan depends on, as one hash (docs/features/competitions-management/participant-import-preview.md D8):
 * the event's participants (id, name, country, external id, player, active, source, how the player was linked - the
 * preview says "linked by themselves"), round entries (id, participant,
 * round, team), teams (id, round, name) and rounds (id, name, category, start - the order rounds are listed and
 * matched in), each in id order. Results are not in it -
 * they arrive all day on event day; the plan's results guard covers them. An event with divisions adds its list, each
 * round's subset and the entries' divisions (shared-puzzles-and-divisions.md §3).
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
        SELECT string_agg(json_build_array(cp.id, cp.name, cp.country, cp.external_id, cp.player_id, cp.deleted_at IS NULL, cp.source, cp.connected_via, cp.email)::text, E'\n' ORDER BY cp.id)
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
        SELECT string_agg(json_build_array(cr.id, cr.name, cr.category, cr.starts_at)::text, E'\n' ORDER BY cr.id)
        FROM competition_round cr
        WHERE cr.competition_id = :competitionId
    ),
    -- Divisions (shared-puzzles-and-divisions.md §3): the event's list, each round's subset and the entries' divisions -
    -- NULL (left out by concat_ws, separator included) for an event without them, so its version stays what it was
    (
        SELECT '#' || string_agg(division_row, E'\n' ORDER BY division_row)
        FROM (
            SELECT json_build_array('d', d.id, d.name, d.short_name, d.position)::text AS division_row
            FROM competition_division d
            WHERE d.competition_id = :competitionId
            UNION ALL
            SELECT json_build_array('r', crd.round_id, crd.division_id)::text
            FROM competition_round_division crd
            INNER JOIN competition_round cr ON cr.id = crd.round_id
            WHERE cr.competition_id = :competitionId
            UNION ALL
            SELECT json_build_array('e', cpr.id, cpr.division_id)::text
            FROM competition_participant_round cpr
            INNER JOIN competition_participant cp ON cp.id = cpr.participant_id
            WHERE cp.competition_id = :competitionId
                AND cpr.division_id IS NOT NULL
            UNION ALL
            SELECT json_build_array('t', ct.id, ct.division_id)::text
            FROM competition_team ct
            INNER JOIN competition_round cr ON cr.id = ct.round_id
            WHERE cr.competition_id = :competitionId
                AND ct.division_id IS NOT NULL
        ) divisions
    )
), 'UTF8')), 'hex')
SQL;

        /** @var string $version */
        $version = $this->database->fetchOne($query, ['competitionId' => $competitionId]);

        return $version;
    }
}
