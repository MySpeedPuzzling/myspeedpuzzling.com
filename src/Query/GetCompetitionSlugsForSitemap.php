<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\OccurrenceDates;

readonly final class GetCompetitionSlugsForSitemap
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Publicly visible standalone events (not part of a series) for route event_detail - approved, not rejected, never a
     * draft (IsCompetitionPubliclyVisible).
     *
     * @return array<string>
     */
    public function standaloneEventSlugs(): array
    {
        $visibility = IsCompetitionPubliclyVisible::SQL_CONDITION;

        $query = <<<SQL
SELECT c.slug
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE {$visibility}
    AND c.series_id IS NULL
    AND c.slug IS NOT NULL
ORDER BY c.slug
SQL;

        /** @var array<string> $slugs */
        $slugs = $this->database
            ->executeQuery($query)
            ->fetchFirstColumn();

        return $slugs;
    }

    /**
     * Publicly visible competition series for route competition_series_detail - approved, not rejected, never a draft
     * (IsSeriesPubliclyVisible).
     *
     * @return array<string>
     */
    public function seriesSlugs(): array
    {
        $visibility = IsSeriesPubliclyVisible::SQL_CONDITION;

        $query = <<<SQL
SELECT cs.slug
FROM competition_series cs
WHERE {$visibility}
    AND cs.slug IS NOT NULL
ORDER BY cs.slug
SQL;

        /** @var array<string> $slugs */
        $slugs = $this->database
            ->executeQuery($query)
            ->fetchFirstColumn();

        return $slugs;
    }

    /**
     * Publicly visible organizations for route organization_detail - approved, not rejected, never a draft
     * (IsOrganizationPubliclyVisible; docs/features/organizations/README.md "Organization page").
     *
     * @return array<string>
     */
    public function organizationSlugs(): array
    {
        $visibility = IsOrganizationPubliclyVisible::SQL_CONDITION;

        $query = <<<SQL
SELECT o.slug
FROM organization o
WHERE {$visibility}
ORDER BY o.slug
SQL;

        /** @var array<string> $slugs */
        $slugs = $this->database
            ->executeQuery($query)
            ->fetchFirstColumn();

        return $slugs;
    }

    /**
     * Publicly visible series editions for route edition_detail - the rule the edition page itself follows
     * (IsCompetitionPubliclyVisible): the series approved, not rejected and no draft, the edition not rejected and no
     * draft. Editions are never approved individually, their own approved_at stays NULL.
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
     * occurrences - one-time events and series editions, each session of one whose rounds fall on separate days.
     * Dated exactly like the events page (GetEventOccurrences): the dates go through OccurrenceDates::sessions() in
     * PHP, because a round's day is read in the event's own zone (RoundTimezone::resolve(), a country's default zone -
     * not known to SQL). One statement over every dated competition, so the sitemap and the legacy
     * `?timePeriod=past` redirect list exactly the years the archive page answers 200 for.
     *
     * @return list<int>
     */
    public function archiveYears(): array
    {
        $visibility = IsCompetitionPubliclyVisible::SQL_CONDITION;
        $rounds = OccurrenceRounds::SQL_JOIN;

        $query = <<<SQL
SELECT
    c.series_id,
    c.date_from,
    c.date_to,
    c.location_country_code AS own_country_code,
    cs.location_country_code AS series_country_code,
    r.rounds
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
{$rounds}
WHERE {$visibility}
    AND (c.date_from IS NOT NULL OR c.date_to IS NOT NULL OR r.rounds IS NOT NULL)
SQL;

        $today = OccurrenceDates::today($this->clock->now());
        $years = [];

        /** @var array{series_id: null|string, date_from: null|string, date_to: null|string, own_country_code: null|string, series_country_code: null|string, rounds: null|string} $row */
        foreach ($this->database->executeQuery($query)->fetchAllAssociative() as $row) {
            $isEdition = $row['series_id'] !== null;

            $sessions = OccurrenceDates::sessions(
                self::instant($row['date_from']),
                self::instant($row['date_to']),
                OccurrenceRounds::fromJson($row['rounds'], $row['own_country_code'], $row['series_country_code']),
            );

            foreach ($sessions as $dates) {
                if ($dates->start === null || $dates->status($today, $isEdition, false) !== EventOccurrenceStatus::Past) {
                    continue;
                }

                $years[(int) $dates->start->format('Y')] = true;
            }
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
