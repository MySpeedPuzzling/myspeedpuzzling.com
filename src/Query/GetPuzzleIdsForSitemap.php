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

    /**
     * @return array<string>
     */
    public function withMarketplaceOffers(): array
    {
        $query = <<<SQL
SELECT DISTINCT p.id
FROM puzzle p
JOIN sell_swap_list_item ssli ON ssli.puzzle_id = p.id
WHERE ssli.published_on_marketplace = true
SQL;

        /** @var array<string> $puzzleIds */
        $puzzleIds = $this->database
            ->executeQuery($query)
            ->fetchFirstColumn();

        return $puzzleIds;
    }
}
