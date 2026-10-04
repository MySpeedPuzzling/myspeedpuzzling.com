<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class GetAllPlayerIdsWithSolveTimes
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<string>
     */
    public function execute(): array
    {
        // Covers both row-owners AND team-only participants (who appear in the JSON array
        // but may never own a row as player_id).
        $sql = <<<SQL
SELECT DISTINCT id FROM (
    SELECT player_id AS id
    FROM puzzle_solving_time
    WHERE suspicious = false

    UNION

    SELECT (elem ->> 'player_id')::uuid AS id
    FROM puzzle_solving_time,
         jsonb_array_elements(team::jsonb -> 'puzzlers') AS elem
    WHERE suspicious = false
      AND team IS NOT NULL
      AND elem ->> 'player_id' IS NOT NULL
) sub
WHERE id IS NOT NULL
ORDER BY id
SQL;

        /** @var list<string> $ids */
        $ids = $this->database->executeQuery($sql)->fetchFirstColumn();

        return $ids;
    }
}
