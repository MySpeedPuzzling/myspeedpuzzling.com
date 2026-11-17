<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * The seating table numbers an event uses (competition toolkit B-qr-competitor-page.md §3.4, printed table tags): the
 * distinct numbers of its round entries - a solo round's people going, a pair/team round's pairs/teams - in every round,
 * or in one. Rounds with table numbers switched off use none. The print page fills the gaps (a table nobody sits at yet
 * still gets its tag). Numbers only, no names. One statement.
 */
readonly final class GetCompetitionTableTags
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<int> ascending
     */
    public function usedNumbers(string $competitionId, null|string $roundId): array
    {
        $roundCondition = $roundId !== null ? 'AND cr.id = :roundId' : '';
        $going = CompetitionParticipantGoing::sql('cp');

        /** @var list<int> $numbers */
        $numbers = $this->database->fetchFirstColumn(
            <<<SQL
SELECT cpr.table_number
FROM competition_participant_round cpr
INNER JOIN competition_round cr ON cr.id = cpr.round_id AND cr.category = 'solo' AND cr.table_numbers_off = false
INNER JOIN competition_participant cp ON cp.id = cpr.participant_id AND {$going}
WHERE cr.competition_id = :competitionId
    {$roundCondition}
    AND cpr.table_number IS NOT NULL
UNION
SELECT ct.table_number
FROM competition_team ct
INNER JOIN competition_round cr ON cr.id = ct.round_id AND cr.category <> 'solo' AND cr.table_numbers_off = false
WHERE cr.competition_id = :competitionId
    {$roundCondition}
    AND ct.table_number IS NOT NULL
ORDER BY 1
SQL,
            array_filter(['competitionId' => $competitionId, 'roundId' => $roundId], static fn (null|string $value): bool => $value !== null),
        );

        return $numbers;
    }
}
