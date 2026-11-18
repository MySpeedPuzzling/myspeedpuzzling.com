<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * A scanned name tag the signed-in scanner may say "this is me" about (docs/features/competitions-management/
 * participant-linking.md §6.4, R12) - competitor pages off: the participant is of the event, going and linked to nobody,
 * and the scanner (their account's player) is not going to the event. One statement; the event page's card then checks
 * the rest (refusals) in its own statement.
 */
readonly final class GetScanClaimTarget
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function offersClaim(string $competitionId, string $participantId, string $userId): bool
    {
        if (Uuid::isValid($competitionId) === false || Uuid::isValid($participantId) === false) {
            return false;
        }

        $listedGoing = CompetitionParticipantGoing::sql('listed');
        $mineGoing = CompetitionParticipantGoing::sql('mine');

        return (bool) $this->database->fetchOne(
            <<<SQL
SELECT EXISTS (
        SELECT 1
        FROM competition_participant listed
        WHERE listed.id = :participantId
            AND listed.competition_id = :competitionId
            AND listed.player_id IS NULL
            AND {$listedGoing}
    )
    AND NOT EXISTS (
        SELECT 1
        FROM competition_participant mine
        INNER JOIN player scanner ON scanner.id = mine.player_id
        WHERE mine.competition_id = :competitionId
            AND scanner.user_id = :userId
            AND {$mineGoing}
    )
SQL,
            ['competitionId' => strtolower($competitionId), 'participantId' => strtolower($participantId), 'userId' => $userId],
        );
    }
}
