<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Numbered pagination of the catalogue pages (brand hub, pieces hub, brand × pieces).
 *
 * Page 1 lives at the page's own URL and pages 2+ at a localised /page/{n} path
 * segment, so every page canonicalises to itself with correct hreflang alternates
 * (base.html.twig builds both from the route parameters). The links are plain
 * <a href> for crawlers: first, last, current ±2 and current ±10.
 */
readonly final class CataloguePagination
{
    public const int PER_PAGE = 48;

    private const int NEIGHBOURS = 2;

    private const int JUMP = 10;

    public int $totalPages;

    public function __construct(
        public int $page,
        public int $totalItems,
        public int $perPage = self::PER_PAGE,
    ) {
        $this->totalPages = max(1, (int) ceil($totalItems / $perPage));
    }

    /**
     * Page 1 always exists (an empty catalogue still renders its page), any
     * other page only when it holds at least one item.
     */
    public function exists(): bool
    {
        return $this->page >= 1 && $this->page <= $this->totalPages;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function isFirstPage(): bool
    {
        return $this->page === 1;
    }

    public function hasPreviousPage(): bool
    {
        return $this->page > 1;
    }

    public function hasNextPage(): bool
    {
        return $this->page < $this->totalPages;
    }

    public function previousPage(): int
    {
        return max(1, $this->page - 1);
    }

    public function nextPage(): int
    {
        return min($this->totalPages, $this->page + 1);
    }

    /**
     * 1-based position of the first item on this page ("Puzzles 49–96 of 6,075").
     */
    public function firstItem(): int
    {
        return $this->totalItems === 0 ? 0 : $this->offset() + 1;
    }

    public function lastItem(): int
    {
        return min($this->totalItems, $this->offset() + $this->perPage);
    }

    /**
     * Page numbers to link, ascending; null marks a gap between two of them.
     * A gap of a single page shows that page instead ("1 2 3", not "1 … 3").
     *
     * @return list<null|int>
     */
    public function links(): array
    {
        $pages = [1, $this->totalPages, $this->page - self::JUMP, $this->page + self::JUMP];

        for ($page = $this->page - self::NEIGHBOURS; $page <= $this->page + self::NEIGHBOURS; $page++) {
            $pages[] = $page;
        }

        $pages = array_values(array_unique(array_filter(
            $pages,
            fn (int $page): bool => $page >= 1 && $page <= $this->totalPages,
        )));
        sort($pages);

        $links = [];
        $previous = null;

        foreach ($pages as $page) {
            if ($previous !== null && $page - $previous === 2) {
                $links[] = $previous + 1;
            } elseif ($previous !== null && $page - $previous > 2) {
                $links[] = null;
            }

            $links[] = $page;
            $previous = $page;
        }

        return $links;
    }
}
