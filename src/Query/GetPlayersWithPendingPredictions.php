<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * Players with at least one solo time with seconds whose prediction was not evaluated yet -
 * the work list of the prediction backfill.
 */
readonly final class GetPlayersWithPendingPredictions
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        /** @var list<string> */
        return $this->database->fetchFirstColumn(<<<'SQL'
            SELECT DISTINCT player_id
            FROM puzzle_solving_time
            WHERE predictable IS NULL
                AND puzzling_type = 'solo'
                AND seconds_to_solve IS NOT NULL
            ORDER BY player_id
            SQL);
    }
}
