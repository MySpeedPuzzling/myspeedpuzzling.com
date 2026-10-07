<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * How many results puzzlers added to a competition (standalone event or series edition) - the number
 * the meta description of a past event quotes. An indexed count on puzzle_solving_time.competition_id.
 *
 * With `$withOfficialResults` (CompetitionEvent::$hasPublishedOfficialResults) the ranked entries of the rounds whose
 * official results are published count too (docs/features/competitions-management/official-results.md) - in the same
 * statement, and an event without them runs exactly what it ran before.
 */
readonly final class CountCompetitionResults
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function forCompetition(string $competitionId, bool $withOfficialResults = false): int
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

        if ($withOfficialResults) {
            $officialCount = GetPublishedRoundResults::sqlRankedEntriesCount('official_round');
            $query = <<<SQL
SELECT ({$query}) + (
    SELECT COALESCE(SUM({$officialCount}), 0)
    FROM competition_round official_round
    WHERE official_round.competition_id = :competitionId
        AND official_round.results_published_at IS NOT NULL
)
SQL;
        }

        $count = $this->database
            ->executeQuery($query, ['competitionId' => $competitionId])
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Results per round of a competition, keyed by round id; rounds without a result are left out.
     * The same results the round results page ranks (competition_round_id is kept current by
     * RoundResultsReconciler, suspicious times are left out) - with `$withOfficialResults` plus the ranked entries of
     * the round's published official results.
     *
     * @return array<string, int>
     */
    public function perRound(string $competitionId, bool $withOfficialResults = false): array
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

        if ($withOfficialResults) {
            $officialCount = GetPublishedRoundResults::sqlRankedEntriesCount('official_round');
            $query = <<<SQL
SELECT round_id, SUM(results_count) AS results_count
FROM (
    {$query}
    UNION ALL
    SELECT official_round.id, {$officialCount}
    FROM competition_round official_round
    WHERE official_round.competition_id = :competitionId
        AND official_round.results_published_at IS NOT NULL
) AS counts (round_id, results_count)
GROUP BY round_id
HAVING SUM(results_count) > 0
SQL;
        }

        /** @var array<string, int|string> $counts */
        $counts = $this->database
            ->executeQuery($query, ['competitionId' => $competitionId])
            ->fetchAllKeyValue();

        return array_map(static fn (int|string $count): int => (int) $count, $counts);
    }
}
