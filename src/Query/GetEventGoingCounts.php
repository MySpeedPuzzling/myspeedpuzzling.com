<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * "41 going" on the events page - spots taken by the going rule (CompetitionParticipantGoing), counts only.
 */
readonly final class GetEventGoingCounts
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $competitionIds
     *
     * @return array<string, int> competition id => spots taken, only competitions with someone going
     */
    public function forCompetitions(array $competitionIds): array
    {
        if ($competitionIds === []) {
            return [];
        }

        $going = CompetitionParticipantGoing::sql('cp');

        $query = <<<SQL
SELECT cp.competition_id, COUNT(*) AS spots_taken
FROM competition_participant cp
WHERE cp.competition_id IN (:ids) AND {$going}
GROUP BY cp.competition_id
SQL;

        $counts = [];

        $rows = $this->database->executeQuery(
            $query,
            ['ids' => array_values(array_unique(array_map(strtolower(...), $competitionIds)))],
            ['ids' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        /** @var array<string, null|string|int|bool> $row */
        foreach ($rows as $row) {
            $counts[(string) $row['competition_id']] = is_numeric($row['spots_taken']) ? (int) $row['spots_taken'] : 0;
        }

        return $counts;
    }
}
