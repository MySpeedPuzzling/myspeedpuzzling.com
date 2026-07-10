<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class GetCompetitionSlugsForSitemap
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Approved standalone events (not part of a series) for route event_detail.
     *
     * @return array<string>
     */
    public function standaloneEventSlugs(): array
    {
        $query = <<<SQL
SELECT slug
FROM competition
WHERE approved_at IS NOT NULL
    AND rejected_at IS NULL
    AND series_id IS NULL
    AND slug IS NOT NULL
ORDER BY slug
SQL;

        /** @var array<string> $slugs */
        $slugs = $this->database
            ->executeQuery($query)
            ->fetchFirstColumn();

        return $slugs;
    }

    /**
     * Approved competition series for route competition_series_detail.
     *
     * @return array<string>
     */
    public function seriesSlugs(): array
    {
        $query = <<<SQL
SELECT slug
FROM competition_series
WHERE approved_at IS NOT NULL
    AND rejected_at IS NULL
    AND slug IS NOT NULL
ORDER BY slug
SQL;

        /** @var array<string> $slugs */
        $slugs = $this->database
            ->executeQuery($query)
            ->fetchFirstColumn();

        return $slugs;
    }

    /**
     * Approved series editions for route edition_detail.
     *
     * @return list<array{series_slug: string, edition_slug: string}>
     */
    public function editionSlugPairs(): array
    {
        $query = <<<SQL
SELECT s.slug AS series_slug, c.slug AS edition_slug
FROM competition c
JOIN competition_series s ON s.id = c.series_id
WHERE c.approved_at IS NOT NULL
    AND c.rejected_at IS NULL
    AND c.slug IS NOT NULL
    AND s.slug IS NOT NULL
ORDER BY s.slug, c.slug
SQL;

        /** @var list<array{series_slug: string, edition_slug: string}> $rows */
        $rows = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        return $rows;
    }
}
