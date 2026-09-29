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

    /**
     * Results per round of a competition, keyed by round id; rounds without a result are left out.
     * The same results the round results page ranks (competition_round_id is kept current by
     * RoundResultsReconciler, suspicious times are left out).
     *
     * @return array<string, int>
     */
    public function perRound(string $competitionId): array
    {
        if (Uuid::isValid($competitionId) === false) {
            return [];
        }

        $query = <<<SQL
SELECT competition_round_id, COUNT(*) AS results_count
FROM puzzle_solving_time
WHERE competition_id = :competitionId
    AND competition_round_id IS NOT NULL
    AND suspicious = false
GROUP BY competition_round_id
SQL;

        /** @var array<string, int|string> $counts */
        $counts = $this->database
            ->executeQuery($query, ['competitionId' => $competitionId])
            ->fetchAllKeyValue();

        return array_map(static fn (int|string $count): int => (int) $count, $counts);
    }
}
