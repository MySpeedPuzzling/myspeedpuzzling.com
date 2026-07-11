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

    public function countApproved(): int
    {
        $query = <<<SQL
SELECT COUNT(puzzle.id)
FROM puzzle
WHERE puzzle.approved = true
    AND (puzzle.hide_image_until IS NULL OR puzzle.hide_image_until <= :now)
    AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now)
SQL;

        $count = $this->database
            ->executeQuery($query, [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @return list<array{id: string, lastmod: null|string}>
     */
    public function approvedPage(int $limit, int $offset): array
    {
        $query = <<<SQL
SELECT puzzle.id, to_char(puzzle.added_at, 'YYYY-MM-DD') AS lastmod
FROM puzzle
WHERE puzzle.approved = true
    AND (puzzle.hide_image_until IS NULL OR puzzle.hide_image_until <= :now)
    AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now)
ORDER BY puzzle.id
LIMIT :limit OFFSET :offset
SQL;

        /** @var list<array{id: string, lastmod: null|string}> $rows */
        $rows = $this->database
            ->executeQuery($query, [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                'limit' => $limit,
                'offset' => $offset,
            ])
            ->fetchAllAssociative();

        return $rows;
    }

    public function countApprovedWithImages(): int
    {
        $query = <<<SQL
SELECT COUNT(puzzle.id)
FROM puzzle
WHERE puzzle.approved = true
    AND puzzle.image IS NOT NULL
    AND (puzzle.hide_image_until IS NULL OR puzzle.hide_image_until <= :now)
    AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now)
SQL;

        $count = $this->database
            ->executeQuery($query, [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            ])
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @return list<array{id: string, lastmod: null|string, image: string}>
     */
    public function approvedPageWithImages(int $limit, int $offset): array
    {
        $query = <<<SQL
SELECT puzzle.id, to_char(puzzle.added_at, 'YYYY-MM-DD') AS lastmod, puzzle.image
FROM puzzle
WHERE puzzle.approved = true
    AND puzzle.image IS NOT NULL
    AND (puzzle.hide_image_until IS NULL OR puzzle.hide_image_until <= :now)
    AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now)
ORDER BY puzzle.id
LIMIT :limit OFFSET :offset
SQL;

        /** @var list<array{id: string, lastmod: null|string, image: string}> $rows */
        $rows = $this->database
            ->executeQuery($query, [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                'limit' => $limit,
                'offset' => $offset,
            ])
            ->fetchAllAssociative();

        return $rows;
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
