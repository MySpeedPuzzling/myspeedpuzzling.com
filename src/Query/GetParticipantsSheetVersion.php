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
 * start, time zone, badge colours, table numbers off, results published at), each in id order - plus the claim policy
 * and its inputs, the link requests (id, status) and how each link was made (participant-linking.md §7.1). One statement.
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
        SELECT json_build_array(c.name, c.is_online, c.registration_managed, c.capacity, c.participant_claims, c.competitor_pages, c.time_proposals)::text
        FROM competition c
        WHERE c.id = :competitionId
    ),
    '#',
    (
        SELECT string_agg(json_build_array(
            cp.id, cp.name, cp.country, cp.external_id, cp.player_id, cp.deleted_at, cp.source,
            cp.registration_status, cp.registered_at, cp.paid_at, cp.checked_in_at, cp.organizer_note,
            cp.email, cp.claim_invited_at, cp.claim_invitations_refused_at
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
    ),
    '#',
    (
        SELECT string_agg(json_build_array(lr.id, lr.status)::text, E'\n' ORDER BY lr.id)
        FROM participant_link_request lr
        WHERE lr.competition_id = :competitionId
    ),
    '#',
    (
        SELECT string_agg(json_build_array(cp.id, cp.connected_via)::text, E'\n' ORDER BY cp.id)
        FROM competition_participant cp
        WHERE cp.competition_id = :competitionId
            AND cp.connected_via IS NOT NULL
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
    ),
    -- Table labels (table-labels-and-plates.md, A10): each labelled round's list - NULL (left out by concat_ws) for an
    -- event without them, so its version stays what it was
    (
        SELECT '#t' || string_agg(json_build_array(cr.id, cr.table_labels)::text, E'\n' ORDER BY cr.id)
        FROM competition_round cr
        WHERE cr.competition_id = :competitionId
            AND cr.table_labels IS NOT NULL
    )
), 'UTF8')), 'hex')
SQL;

        /** @var string $version */
        $version = $this->database->fetchOne($query, ['competitionId' => strtolower($competitionId)]);

        return $version;
    }
}
