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

    SELECT (jsonb_array_elements(team::jsonb -> 'puzzlers') ->> 'player_id')::uuid AS id
    FROM puzzle_solving_time
    WHERE suspicious = false AND team IS NOT NULL
) sub
ORDER BY id
SQL;

        /** @var list<string> $ids */
        $ids = $this->database->executeQuery($sql)->fetchFirstColumn();

        return $ids;
    }
}
