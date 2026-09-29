<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * How many results puzzlers added to a competition (standalone event or series edition) - the number
 * the meta description of a past event quotes. An indexed count on puzzle_solving_time.competition_id.
 */
readonly final class CountCompetitionResults
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function forCompetition(string $competitionId): int
    {
        if (Uuid::isValid($competitionId) === false) {
            return 0;
        }

        $query = <<<SQL
SELECT COUNT(*)
FROM puzzle_solving_time
WHERE competition_id = :competitionId
    AND suspicious = false
SQL;

        $count = $this->database
            ->executeQuery($query, ['competitionId' => $competitionId])
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }
}
