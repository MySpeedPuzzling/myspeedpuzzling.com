<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * Everything the participants sheet shows that the sheet itself (and registrations, imports, the old editors) change,
 * as one hash - the version the page compares to know whether its model still is the server's
 * (docs/features/competitions-management/participants-spreadsheet.md, delivery contract §3.2): the event itself (name,
 * online, managed registration, capacity), its participants (id, name, country, external id, player, removed at,
 * source, registration status, registered at, paid at, checked in at, organiser's note), round entries (id,
 * participant, round, pair/team), pairs/teams (id, round, name) and rounds (id, name, category, expected team size,
 * start, time zone, badge colours, table numbers off, results published at), each in id order. One statement.
 *
 * Official results, table numbers and qualified marks are NOT in it: they change all day on event day and travel on the
 * rounds' own topics (OfficialResultsLiveUpdates) - a page merges those by entry, not by fetching everything again.
 */
readonly final class GetParticipantsSheetVersion
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
        SELECT json_build_array(c.name, c.is_online, c.registration_managed, c.capacity)::text
        FROM competition c
        WHERE c.id = :competitionId
    ),
    '#',
    (
        SELECT string_agg(json_build_array(
            cp.id, cp.name, cp.country, cp.external_id, cp.player_id, cp.deleted_at, cp.source,
            cp.registration_status, cp.registered_at, cp.paid_at, cp.checked_in_at, cp.organizer_note
        )::text, E'\n' ORDER BY cp.id)
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
        SELECT string_agg(json_build_array(
            cr.id, cr.name, cr.category, cr.team_size, cr.starts_at, cr.timezone, cr.badge_background_color,
            cr.badge_text_color, cr.table_numbers_off, cr.results_published_at
        )::text, E'\n' ORDER BY cr.id)
        FROM competition_round cr
        WHERE cr.competition_id = :competitionId
    )
), 'UTF8')), 'hex')
SQL;

        /** @var string $version */
        $version = $this->database->fetchOne($query, ['competitionId' => strtolower($competitionId)]);

        return $version;
    }
}
