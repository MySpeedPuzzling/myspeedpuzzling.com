<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

readonly final class GetPuzzleIdsForSitemap
{
    /**
     * The day every puzzle page was last rebuilt - the floor of every puzzle's `lastmod`. Bump it (to the deploy
     * date of the change) only when EVERY puzzle page changes its main content, title/meta description,
     * structured data or links - never for styling or the page chrome around it. Google uses `lastmod` only
     * while it stays accurate, so this is never "today" or set automatically: a sitemap claiming changes that
     * did not happen teaches Google to ignore it (docs/features/seo/implementation-plan-2026-10.md "Sitemap
     * lastmod floor").
     *
     * 2026-10-06: title, meta description, the "About this puzzle" summary, catalogue links and the Product /
     * BreadcrumbList structured data of every puzzle page (2026-09-30 .. 2026-10-05). Deployed late on 2026-10-05
     * UTC, so the day after: a copy Google fetched earlier on 10-05 must not look as new as the rebuilt page.
     *
     * 2026-10-08: ItemPage structured data naming the preview image (primaryImageOfPage) on every puzzle page
     * with a picture, and the related puzzles' boxes are no longer images Google indexes - Google showed another
     * puzzle's box next to puzzle pages. Deployed late on 2026-10-07 UTC, the day after for the same reason.
     */
    public const string PAGE_LAST_REBUILT_AT = '2026-10-08';

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
     * `lastmod` is the day the puzzle page last changed in a way worth recrawling: the puzzle was
     * added, approved, its names changed (title, "Also known as" - names_changed_at), or somebody
     * logged a time on it (the page shows every time) - never earlier than PAGE_LAST_REBUILT_AT, the last
     * change of every puzzle page at once (GREATEST skips the NULL dates). The page of
     * puzzles is cut first and only its rows are aggregated - one scan of the solving times,
     * 0.1-0.45 s per sitemap file (1 666 puzzles, or 20 000 for images) on the production copy,
     * whatever the offset.
     *
     * @return list<array{id: string, lastmod: string}>
     */
    public function approvedPage(int $limit, int $offset): array
    {
        $query = <<<SQL
SELECT
    page.id,
    to_char(GREATEST(page.added_at, page.approved_at, page.names_changed_at, MAX(puzzle_solving_time.tracked_at), CAST(:pageLastRebuiltAt AS timestamp)), 'YYYY-MM-DD') AS lastmod
FROM (
    SELECT puzzle.id, puzzle.added_at, puzzle.approved_at, puzzle.names_changed_at
    FROM puzzle
    WHERE puzzle.approved = true
        AND (puzzle.hide_image_until IS NULL OR puzzle.hide_image_until <= :now)
        AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now)
    ORDER BY puzzle.id
    LIMIT :limit OFFSET :offset
) page
LEFT JOIN puzzle_solving_time ON puzzle_solving_time.puzzle_id = page.id
GROUP BY page.id, page.added_at, page.approved_at, page.names_changed_at
ORDER BY page.id
SQL;

        /** @var list<array{id: string, lastmod: string}> $rows */
        $rows = $this->database
            ->executeQuery($query, [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                'limit' => $limit,
                'offset' => $offset,
                'pageLastRebuiltAt' => self::PAGE_LAST_REBUILT_AT,
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
     * Same `lastmod` rule as approvedPage().
     *
     * @return list<array{id: string, lastmod: string, image: string}>
     */
    public function approvedPageWithImages(int $limit, int $offset): array
    {
        $query = <<<SQL
SELECT
    page.id,
    to_char(GREATEST(page.added_at, page.approved_at, page.names_changed_at, MAX(puzzle_solving_time.tracked_at), CAST(:pageLastRebuiltAt AS timestamp)), 'YYYY-MM-DD') AS lastmod,
    page.image
FROM (
    SELECT puzzle.id, puzzle.added_at, puzzle.approved_at, puzzle.names_changed_at, puzzle.image
    FROM puzzle
    WHERE puzzle.approved = true
        AND puzzle.image IS NOT NULL
        AND (puzzle.hide_image_until IS NULL OR puzzle.hide_image_until <= :now)
        AND (puzzle.hide_until IS NULL OR puzzle.hide_until <= :now)
    ORDER BY puzzle.id
    LIMIT :limit OFFSET :offset
) page
LEFT JOIN puzzle_solving_time ON puzzle_solving_time.puzzle_id = page.id
GROUP BY page.id, page.added_at, page.approved_at, page.names_changed_at, page.image
ORDER BY page.id
SQL;

        /** @var list<array{id: string, lastmod: string, image: string}> $rows */
        $rows = $this->database
            ->executeQuery($query, [
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                'limit' => $limit,
                'offset' => $offset,
                'pageLastRebuiltAt' => self::PAGE_LAST_REBUILT_AT,
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
