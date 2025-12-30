<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class GetPuzzleIdsForSitemap
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<string>
     */
    public function allApproved(): array
    {
        $query = <<<SQL
SELECT puzzle.id
FROM puzzle
WHERE puzzle.approved = true
SQL;

        /** @var array<string> $puzzleIds */
        $puzzleIds = $this->database
            ->executeQuery($query)
            ->fetchFirstColumn();

        return $puzzleIds;
    }
}
