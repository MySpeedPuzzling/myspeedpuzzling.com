<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * The claim invitation rules per address over every row of the event, removed ones included (docs/features/
 * competitions-management/participant-linking.md R8): a person removed and added again is the same address - their
 * "no more invitations for this event" and the one invitation per 24 hours hold for it (families sharing an address
 * get one e-mail a day). One statement.
 */
readonly final class GetClaimInvitationAddresses
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array{refused: array<string, true>, lastInvitedAt: array<string, DateTimeImmutable>} keyed by the address
     */
    public function ofCompetition(string $competitionId): array
    {
        $refused = [];
        $lastInvitedAt = [];

        if (Uuid::isValid($competitionId) === false) {
            return ['refused' => $refused, 'lastInvitedAt' => $lastInvitedAt];
        }

        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT email, bool_or(claim_invitations_refused_at IS NOT NULL) AS refused, MAX(claim_invited_at) AS last_invited_at
FROM competition_participant
WHERE competition_id = :competitionId AND email IS NOT NULL
    AND (claim_invitations_refused_at IS NOT NULL OR claim_invited_at IS NOT NULL)
GROUP BY email
SQL,
            ['competitionId' => $competitionId],
        );

        foreach ($rows as $row) {
            /** @var array{email: string, refused: bool, last_invited_at: null|string} $row */
            if ($row['refused']) {
                $refused[$row['email']] = true;
            }

            if ($row['last_invited_at'] !== null) {
                $lastInvitedAt[$row['email']] = new DateTimeImmutable($row['last_invited_at']);
            }
        }

        return ['refused' => $refused, 'lastInvitedAt' => $lastInvitedAt];
    }
}
