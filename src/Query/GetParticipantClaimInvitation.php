<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\ParticipantClaimInvitation;

/**
 * One participant as the claim invitation e-mail needs them (docs/features/competitions-management/
 * participant-linking.md §9.1) - the organiser's name, country and address of the person, the claim link, and the event:
 * its name, days, place, public organization, visibility (IsCompetitionPubliclyVisible) and online timing. One
 * statement; null for an unknown participant.
 */
readonly final class GetParticipantClaimInvitation
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function byId(string $participantId): null|ParticipantClaimInvitation
    {
        if (Uuid::isValid($participantId) === false) {
            return null;
        }

        $visible = IsCompetitionPubliclyVisible::SQL_CONDITION;
        $organizationJoin = IsOrganizationPubliclyVisible::SQL_JOIN_OF_COMPETITION;
        $organizationVisible = IsOrganizationPubliclyVisible::SQL_CONDITION;

        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT
    cp.id AS participant_id,
    cp.competition_id,
    cp.name AS participant_name,
    cp.country AS participant_country,
    cp.email,
    cp.claim_token,
    cp.deleted_at IS NOT NULL AS removed,
    cp.player_id IS NOT NULL AS linked,
    -- "No more invitations for this event" per address over the event's rows, removed ones included (R8)
    (cp.claim_invitations_refused_at IS NOT NULL OR EXISTS (
        SELECT 1 FROM competition_participant same_address
        WHERE same_address.competition_id = cp.competition_id
            AND same_address.email = cp.email
            AND same_address.claim_invitations_refused_at IS NOT NULL
    )) AS refused,
    c.name AS competition_name,
    CASE WHEN o.id IS NOT NULL AND {$organizationVisible} THEN o.name END AS organization_name,
    c.date_from,
    c.date_to,
    c.location,
    {$visible} AS publicly_visible,
    EXISTS (
        SELECT 1 FROM online_round
        INNER JOIN competition_round online_timing_round ON online_timing_round.id = online_round.round_id
        WHERE online_timing_round.competition_id = c.id
    ) AS uses_online_timing
FROM competition_participant cp
INNER JOIN competition c ON c.id = cp.competition_id
LEFT JOIN competition_series cs ON cs.id = c.series_id
{$organizationJoin}
WHERE cp.id = :participantId
SQL,
            ['participantId' => $participantId],
        );

        if ($row === false) {
            return null;
        }

        /** @var array{participant_id: string, competition_id: string, participant_name: string, participant_country: null|string, email: null|string, claim_token: null|string, removed: bool, linked: bool, refused: bool, competition_name: string, organization_name: null|string, date_from: null|string, date_to: null|string, location: null|string, publicly_visible: bool, uses_online_timing: bool} $row */
        return new ParticipantClaimInvitation(
            participantId: $row['participant_id'],
            competitionId: $row['competition_id'],
            participantName: $row['participant_name'],
            participantCountry: $row['participant_country'],
            email: $row['email'],
            claimToken: $row['claim_token'],
            removed: $row['removed'],
            linked: $row['linked'],
            refused: $row['refused'],
            competitionName: $row['competition_name'],
            organizationName: $row['organization_name'],
            dateFrom: $row['date_from'] !== null ? new DateTimeImmutable($row['date_from']) : null,
            dateTo: $row['date_to'] !== null ? new DateTimeImmutable($row['date_to']) : null,
            location: $row['location'],
            publiclyVisible: $row['publicly_visible'],
            usesOnlineTiming: $row['uses_online_timing'],
        );
    }
}
