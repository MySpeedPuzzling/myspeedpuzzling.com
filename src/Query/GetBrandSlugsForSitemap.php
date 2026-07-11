<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\BrandHubStats;

readonly final class GetBrandSlugsForSitemap
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Slugs of brands whose hub pages are indexable: approved, at least
     * BrandHubStats::MIN_INDEXABLE_PUZZLES visible puzzles and at least one
     * recorded solve (same gating rule as the noindex meta on the hub page).
     *
     * @return list<string>
     */
    public function indexable(): array
    {
        $query = <<<SQL
SELECT manufacturer.slug
FROM manufacturer
INNER JOIN puzzle ON puzzle.manufacturer_id = manufacturer.id
    AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
LEFT JOIN puzzle_solving_time pst ON pst.puzzle_id = puzzle.id
    AND pst.seconds_to_solve IS NOT NULL
WHERE manufacturer.approved = true
    AND manufacturer.slug IS NOT NULL
GROUP BY manufacturer.id, manufacturer.slug
HAVING COUNT(DISTINCT puzzle.id) >= :minPuzzles AND COUNT(pst.id) > 0
ORDER BY manufacturer.slug
SQL;

        /** @var list<string> $slugs */
        $slugs = $this->database
            ->executeQuery($query, [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                'minPuzzles' => BrandHubStats::MIN_INDEXABLE_PUZZLES,
            ])
            ->fetchFirstColumn();

        return $slugs;
    }
}
