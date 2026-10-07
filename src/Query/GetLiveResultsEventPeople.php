<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * The live entry's "Already on the list?" beyond the round (docs/features/competitions-management/live-results.md):
 * the event's people who are no entry of the round yet - not in a solo round, in no pair/team of a pair/team round.
 * Picked there, they are put into the round by their id (RecordRoundResults `newEntry.participantId`) instead of being
 * typed in as a second person. Organiser tooling behind COMPETITION_EDIT: names as the organiser recorded them, no
 * blocklist; people removed from the event left out, waitlisted ones in (somebody may turn up). One statement.
 */
readonly final class GetLiveResultsEventPeople
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<array{participantId: string, name: string, country: null|string, playerCode: null|string}>
     */
    public function notInRound(string $competitionId, string $roundId): array
    {
        $rows = $this->database->fetchAllAssociative(
            <<<SQL
SELECT cp.id, cp.name, cp.country, player.code AS player_code
FROM competition_participant cp
LEFT JOIN player ON player.id = cp.player_id
WHERE cp.competition_id = :competitionId
    AND cp.deleted_at IS NULL
    AND NOT EXISTS (
        SELECT 1
        FROM competition_participant_round cpr
        INNER JOIN competition_round cr ON cr.id = cpr.round_id
        WHERE cpr.participant_id = cp.id
            AND cpr.round_id = :roundId
            AND (cr.category = 'solo' OR cpr.team_id IS NOT NULL)
    )
ORDER BY LOWER(cp.name), cp.id
SQL,
            ['competitionId' => $competitionId, 'roundId' => $roundId],
        );

        return array_map(static function (array $row): array {
            /** @var array{id: string, name: string, country: null|string, player_code: null|string} $row */
            return [
                'participantId' => $row['id'],
                'name' => $row['name'],
                'country' => $row['country'],
                'playerCode' => $row['player_code'] !== null ? strtoupper($row['player_code']) : null,
            ];
        }, $rows);
    }
}
