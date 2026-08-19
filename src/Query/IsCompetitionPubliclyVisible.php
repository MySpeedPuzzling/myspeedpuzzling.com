<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * The single public-visibility rule for a competition row, whether it is a standalone event or
 * an edition of a series:
 *
 * - standalone (series_id IS NULL): approved and not rejected;
 * - edition (series_id IS NOT NULL): the PARENT SERIES is approved and not rejected — editions are
 *   never approved individually (their own approved_at stays NULL, ConvertCompetitionToSeriesHandler
 *   even clears it), the series approval governs all of them.
 *
 * In both cases a rejected competition row vetoes a stale approval (approve() and reject() do not
 * clear each other).
 */
readonly final class IsCompetitionPubliclyVisible
{
    /**
     * The visibility predicate as a WHERE fragment, so list queries can embed the exact same rule
     * instead of re-typing it. Requires aliases `c` = competition and `cs` = competition_series
     * LEFT JOINed on `cs.id = c.series_id`.
     */
    public const string SQL_CONDITION = <<<SQL
c.rejected_at IS NULL
AND (
    (c.series_id IS NULL AND c.approved_at IS NOT NULL)
    OR (c.series_id IS NOT NULL AND cs.approved_at IS NOT NULL AND cs.rejected_at IS NULL)
)
SQL;

    public function __construct(
        private Connection $database,
    ) {
    }

    public function check(string $competitionId): bool
    {
        if (Uuid::isValid($competitionId) === false) {
            return false;
        }

        $condition = self::SQL_CONDITION;

        $query = <<<SQL
SELECT 1
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE c.id = :competitionId
    AND {$condition}
LIMIT 1
SQL;

        $result = $this->database
            ->executeQuery($query, [
                'competitionId' => $competitionId,
            ])
            ->fetchOne();

        return $result !== false;
    }
}
