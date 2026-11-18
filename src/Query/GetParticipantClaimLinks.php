<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\ParticipantClaimLinkRow;
use SpeedPuzzling\Web\Value\RegistrationStatus;

/**
 * The people of an event a personal claim link is for (docs/features/competitions-management/participant-linking.md
 * §7.4, §8.1) - organiser tooling behind COMPETITION_EDIT, the claim slips and the claim links CSV: on the list (not
 * removed - the waitlist marked), not linked to an account, by name. Only the organiser's own records are read, no
 * profile. One statement.
 */
readonly final class GetParticipantClaimLinks
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<ParticipantClaimLinkRow>
     */
    public function ofCompetition(string $competitionId): array
    {
        if (Uuid::isValid($competitionId) === false) {
            return [];
        }

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT
    cp.id,
    cp.name,
    cp.country,
    cp.external_id,
    cp.claim_token,
    cp.registration_status IS NOT DISTINCT FROM :waitlisted AS waitlisted
FROM competition_participant cp
WHERE cp.competition_id = :competitionId
    AND cp.deleted_at IS NULL
    AND cp.player_id IS NULL
ORDER BY cp.name, cp.id
SQL,
            ['competitionId' => strtolower($competitionId), 'waitlisted' => RegistrationStatus::Waitlisted->value],
        );

        return array_map(static function (array $row): ParticipantClaimLinkRow {
            /** @var array{id: string, name: string, country: null|string, external_id: null|string, claim_token: null|string, waitlisted: bool} $row */
            return new ParticipantClaimLinkRow(
                participantId: strtolower($row['id']),
                name: $row['name'],
                country: $row['country'],
                externalId: $row['external_id'],
                waitlisted: $row['waitlisted'],
                token: $row['claim_token'],
            );
        }, $rows);
    }
}
