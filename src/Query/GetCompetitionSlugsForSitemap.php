<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\OccurrenceDates;
use SpeedPuzzling\Web\Value\RoundTimezone;

readonly final class GetCompetitionSlugsForSitemap
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
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
     * Publicly visible series editions for route edition_detail - the rule the edition page itself follows
     * (IsCompetitionPubliclyVisible): the series approved and not rejected, the edition not rejected.
     * Editions are never approved individually, their own approved_at stays NULL.
     *
     * @return list<array{series_slug: string, edition_slug: string}>
     */
    public function editionSlugPairs(): array
    {
        $visibility = IsCompetitionPubliclyVisible::SQL_CONDITION;

        $query = <<<SQL
SELECT cs.slug AS series_slug, c.slug AS edition_slug
FROM competition c
JOIN competition_series cs ON cs.id = c.series_id
WHERE {$visibility}
    AND c.slug IS NOT NULL
    AND cs.slug IS NOT NULL
ORDER BY cs.slug, c.slug
SQL;

        /** @var list<array{series_slug: string, edition_slug: string}> $rows */
        $rows = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        return $rows;
    }

    /**
     * Round result pages worth indexing: publicly visible competition, round with a slug and at least one
     * result - an empty ranking is not a page anyone searches for.
     *
     * @return list<array{event_slug: string, series_slug: null|string, round_slug: string}>
     */
    public function roundResultSlugs(): array
    {
        $visibility = IsCompetitionPubliclyVisible::SQL_CONDITION;
        // A round page with published official results has something to show too (official-results.md)
        $showsOfficialResults = GetPublishedRoundResults::sqlShowsOfficialResults('cr');

        $query = <<<SQL
SELECT c.slug AS event_slug, cs.slug AS series_slug, cr.slug AS round_slug
FROM competition_round cr
JOIN competition c ON c.id = cr.competition_id
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE {$visibility}
    AND c.slug IS NOT NULL
    AND cr.slug IS NOT NULL
    AND (c.series_id IS NULL OR cs.slug IS NOT NULL)
    AND (EXISTS (SELECT 1 FROM puzzle_solving_time pst WHERE pst.competition_round_id = cr.id) OR {$showsOfficialResults})
ORDER BY cs.slug NULLS FIRST, c.slug, cr.starts_at
SQL;

        /** @var list<array{event_slug: string, series_slug: null|string, round_slug: string}> $rows */
        $rows = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        return $rows;
    }

    /**
     * Years of the events archive (`events_archive`), newest first: the start years of past publicly visible
     * occurrences - one-time events and series editions. Dated exactly like the events page (GetEventOccurrences):
     * the dates go through OccurrenceDates in PHP, because an edition's day is its first round's start in the event's
     * own zone (RoundTimezone::resolve(), a country's default zone - not known to SQL). One statement over every
     * dated competition, so the sitemap and the legacy `?timePeriod=past` redirect list exactly the years the archive
     * page answers 200 for.
     *
     * @return list<int>
     */
    public function archiveYears(): array
    {
        $visibility = IsCompetitionPubliclyVisible::SQL_CONDITION;

        $query = <<<SQL
SELECT
    c.series_id,
    c.date_from,
    c.date_to,
    c.location_country_code AS own_country_code,
    cs.location_country_code AS series_country_code,
    r.first_starts_at,
    r.last_starts_at,
    r.round_timezone
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
LEFT JOIN (
    SELECT competition_id,
        MIN(starts_at) AS first_starts_at,
        MAX(starts_at) AS last_starts_at,
        MIN(timezone) AS round_timezone
    FROM competition_round
    GROUP BY competition_id
) r ON r.competition_id = c.id
WHERE {$visibility}
    AND (c.date_from IS NOT NULL OR c.date_to IS NOT NULL OR (c.series_id IS NOT NULL AND r.first_starts_at IS NOT NULL))
SQL;

        $today = OccurrenceDates::today($this->clock->now());
        $years = [];

        /** @var array{series_id: null|string, date_from: null|string, date_to: null|string, own_country_code: null|string, series_country_code: null|string, first_starts_at: null|string, last_starts_at: null|string, round_timezone: null|string} $row */
        foreach ($this->database->executeQuery($query)->fetchAllAssociative() as $row) {
            $isEdition = $row['series_id'] !== null;

            $dates = $isEdition
                ? OccurrenceDates::ofEdition(
                    self::instant($row['first_starts_at']),
                    self::instant($row['last_starts_at']),
                    RoundTimezone::resolve($row['round_timezone'], $row['own_country_code'], $row['series_country_code']),
                    self::instant($row['date_from']),
                    self::instant($row['date_to']),
                )
                : OccurrenceDates::ofEvent(self::instant($row['date_from']), self::instant($row['date_to']));

            if ($dates->start === null || $dates->status($today, $isEdition, false) !== EventOccurrenceStatus::Past) {
                continue;
            }

            $years[(int) $dates->start->format('Y')] = true;
        }

        $years = array_keys($years);
        rsort($years);

        return $years;
    }

    /**
     * A stored timestamp (UTC, without zone)
     */
    private static function instant(null|string $value): null|DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
