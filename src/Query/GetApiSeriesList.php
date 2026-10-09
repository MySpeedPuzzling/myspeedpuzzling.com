<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\ApiSeriesListItem;

/**
 * The series API v1 lists (GET /api/v1/series, docs/features/events-page/high-frequency-series.md "API v1", P16): every
 * publicly visible series - never a draft, one waiting for approval or a rejected one - with the day facts of its
 * publicly visible editions (SeriesEditionDays) and the name of its organization when that one is publicly visible.
 * One statement, by name.
 */
readonly final class GetApiSeriesList
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<ApiSeriesListItem>
     */
    public function all(): array
    {
        $seriesVisible = IsSeriesPubliclyVisible::SQL_CONDITION;
        $organizationVisible = IsOrganizationPubliclyVisible::SQL_CONDITION;
        $editionDays = SeriesEditionDays::sqlJoin('sed', ':today');

        $query = <<<SQL
SELECT
    cs.id,
    cs.name,
    cs.shortcut,
    cs.slug,
    cs.logo,
    cs.is_online,
    cs.location,
    cs.location_country_code,
    cs.link,
    CASE WHEN {$organizationVisible} THEN o.name END AS organization_name,
    sed.edition_count AS editions_count,
    sed.next_day AS next_date,
    sed.last_past_day AS last_date
FROM competition_series cs
LEFT JOIN organization o ON o.id = cs.organization_id
{$editionDays}
WHERE {$seriesVisible}
ORDER BY LOWER(cs.name), cs.id
SQL;

        $rows = $this->database->fetchAllAssociative($query, [
            'today' => $this->clock->now()->format('Y-m-d'),
        ]);

        return array_map(static function (array $row): ApiSeriesListItem {
            /**
             * @var array{
             *     id: string,
             *     name: string,
             *     shortcut: null|string,
             *     slug: null|string,
             *     logo: null|string,
             *     is_online: bool,
             *     location: null|string,
             *     location_country_code: null|string,
             *     link: null|string,
             *     organization_name: null|string,
             *     editions_count: int,
             *     next_date: null|string,
             *     last_date: null|string,
             * } $row
             */
            return ApiSeriesListItem::fromDatabaseRow($row);
        }, $rows);
    }
}
