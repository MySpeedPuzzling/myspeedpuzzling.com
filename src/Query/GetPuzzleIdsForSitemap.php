<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

readonly final class GetPuzzleIdsForSitemap
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
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
    AND (puzzle.hide_image_until IS NULL OR puzzle.hide_image_until <= :now)
    AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now)
SQL;

        /** @var array<string> $puzzleIds */
        $puzzleIds = $this->database
            ->executeQuery($query, [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
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
AND (p.hide_image_until IS NULL OR p.hide_image_until <= :now)
SQL;

        /** @var array<string> $puzzleIds */
        $puzzleIds = $this->database
            ->executeQuery($query, [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->fetchFirstColumn();

        return $puzzleIds;
    }
}
