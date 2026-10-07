<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\LiveResultsEntrant;

/**
 * The participant behind a name tag's QR (docs/features/competitions-management/live-results.md) - only an active
 * participant of the given event; anything else (unknown, another event's, removed from the event) is null.
 * Organiser tooling: the name as the organiser recorded it.
 */
readonly final class GetLiveResultsEntrant
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function ofCompetition(string $competitionId, string $participantId): null|LiveResultsEntrant
    {
        if (!Uuid::isValid($competitionId) || !Uuid::isValid($participantId)) {
            return null;
        }

        $row = $this->database->fetchAssociative(
            <<<SQL
SELECT cp.id, cp.competition_id, cp.name
FROM competition_participant cp
WHERE cp.id = :participantId
    AND cp.competition_id = :competitionId
    AND cp.deleted_at IS NULL
SQL,
            ['participantId' => $participantId, 'competitionId' => $competitionId],
        );

        if ($row === false) {
            return null;
        }

        /** @var array{id: string, competition_id: string, name: string} $row */

        /** @var list<string> $roundIds */
        $roundIds = $this->database->fetchFirstColumn(
            'SELECT DISTINCT cpr.round_id FROM competition_participant_round cpr WHERE cpr.participant_id = :participantId',
            ['participantId' => $participantId],
        );

        return new LiveResultsEntrant(
            participantId: $row['id'],
            competitionId: $row['competition_id'],
            name: $row['name'],
            roundIds: $roundIds,
        );
    }
}
