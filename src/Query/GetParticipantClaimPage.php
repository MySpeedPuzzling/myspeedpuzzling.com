<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\ParticipantClaimPage;
use SpeedPuzzling\Web\Value\OnlineToken;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RegistrationStatus;

/**
 * A personal claim link's page (docs/features/competitions-management/participant-linking.md §6.5, §8.1): the token's
 * participant with its event, whether the event is publicly visible (IsCompetitionPubliclyVisible - the page names
 * nothing of a draft to anybody off its team, DraftCanaryTest) and, for a signed-in viewer, the other row of the event
 * they are on the list as. No player identity is read - the organiser's names only. One statement; a malformed token
 * none.
 */
readonly final class GetParticipantClaimPage
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function byToken(string $token, null|string $viewerPlayerId): null|ParticipantClaimPage
    {
        if (OnlineToken::looksValid($token) === false) {
            return null;
        }

        $visible = IsCompetitionPubliclyVisible::SQL_CONDITION;
        $viewer = $viewerPlayerId !== null && Uuid::isValid($viewerPlayerId) ? $viewerPlayerId : null;

        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT
    cp.id AS participant_id,
    cp.name AS participant_name,
    cp.country AS participant_country,
    cp.deleted_at IS NOT NULL AS removed,
    cp.player_id AS linked_player_id,
    c.id AS competition_id,
    c.name AS competition_name,
    c.date_from,
    c.date_to,
    c.location,
    {$visible} AS publicly_visible,
    other.name AS viewer_other_name,
    COALESCE(other.source = :selfJoined AND other.registration_status = :paid AND c.registration_managed, false) AS viewer_other_paid
FROM competition_participant cp
INNER JOIN competition c ON c.id = cp.competition_id
LEFT JOIN competition_series cs ON cs.id = c.series_id
LEFT JOIN LATERAL (
    SELECT own.name, own.source, own.registration_status
    FROM competition_participant own
    WHERE own.competition_id = cp.competition_id
        AND own.player_id = CAST(:viewer AS uuid)
        AND own.deleted_at IS NULL
        AND own.id <> cp.id
    ORDER BY own.id
    LIMIT 1
) other ON true
WHERE cp.claim_token = :token
SQL,
            [
                'token' => $token,
                'viewer' => $viewer,
                'selfJoined' => ParticipantSource::SelfJoined->value,
                'paid' => RegistrationStatus::Paid->value,
            ],
        );

        if ($row === false) {
            return null;
        }

        /** @var array{participant_id: string, participant_name: string, participant_country: null|string, removed: bool, linked_player_id: null|string, competition_id: string, competition_name: string, date_from: null|string, date_to: null|string, location: null|string, publicly_visible: bool, viewer_other_name: null|string, viewer_other_paid: bool} $row */
        return new ParticipantClaimPage(
            participantId: $row['participant_id'],
            participantName: $row['participant_name'],
            participantCountry: $row['participant_country'],
            removed: $row['removed'],
            linkedPlayerId: $row['linked_player_id'],
            competitionId: $row['competition_id'],
            competitionName: $row['competition_name'],
            dateFrom: $row['date_from'] !== null ? new DateTimeImmutable($row['date_from']) : null,
            dateTo: $row['date_to'] !== null ? new DateTimeImmutable($row['date_to']) : null,
            location: $row['location'],
            publiclyVisible: $row['publicly_visible'],
            viewerOtherName: $row['viewer_other_name'],
            viewerOtherPaid: $row['viewer_other_paid'],
        );
    }
}
