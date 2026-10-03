<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\EventAttendance;

/**
 * The "I'm going" state of a competition page - one statement for a signed-in player, none for a visitor.
 * Standalone events and series editions share it, as they share the whole join flow
 * (docs/features/competitions-management/participants.md).
 */
readonly final class GetEventAttendance
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function forPlayer(string $competitionId, null|string $playerId): EventAttendance
    {
        if ($playerId === null) {
            return EventAttendance::notGoing();
        }

        $query = <<<SQL
SELECT
    EXISTS (
        SELECT 1
        FROM competition_participant
        WHERE competition_id = :competitionId
        AND player_id = :playerId
        AND deleted_at IS NULL
    ) AS is_going,
    EXISTS (
        SELECT 1
        FROM competition_participant
        WHERE competition_id = :competitionId
        AND player_id IS NULL
        AND deleted_at IS NULL
    ) AS has_not_connected_participants
SQL;

        /** @var array{is_going: bool, has_not_connected_participants: bool} $row */
        $row = $this->database
            ->executeQuery($query, [
                'competitionId' => $competitionId,
                'playerId' => $playerId,
            ])
            ->fetchAssociative();

        return new EventAttendance(
            isGoing: $row['is_going'],
            canChangeParticipant: $row['is_going'] && $row['has_not_connected_participants'],
        );
    }
}
