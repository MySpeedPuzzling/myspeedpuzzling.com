<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PastEventMatch;
use SpeedPuzzling\Web\Services\SeriesEditions\SeriesEditionSearch;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * "Is it one of these?" of the creation wizard's backfill path (docs/features/competitions-management/
 * creation-wizard.md "Backfill") - before anything is created, the events, series and organizations the player may be
 * about to add again. Publicly visible ones (IsCompetitionPubliclyVisible / IsSeriesPubliclyVisible /
 * IsOrganizationPubliclyVisible) plus the viewer's own not yet public ones (the ids they may edit, passed in); never a
 * rejected one.
 *
 * Name words are folded (immutable_unaccent, ILIKE) and counted; a year or a number alone never matches. An event is a
 * candidate when it is dated within 3 days of the typed date and shares a name word, the country or "online" - or when
 * it holds every typed word. A series holds every word, or one of them and has a date within 3 days; an organization
 * holds every word.
 */
readonly final class GetPastEventMatches
{
    public const int EVENTS_LIMIT = 6;
    public const int SERIES_LIMIT = 3;
    public const int ORGANIZATIONS_LIMIT = 3;
    private const int DAYS = 3;
    private const int MAX_WORDS = 6;
    private const string NO_ID = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $ownCompetitionIds events the viewer may edit (their not yet public ones are offered too)
     * @param list<string> $ownSeriesIds
     * @param list<string> $ownOrganizationIds
     * @return list<PastEventMatch> events first, then series, then organizations
     */
    public function find(
        string $name,
        null|DateTimeImmutable $date,
        null|string $countryCode,
        bool $isOnline,
        array $ownCompetitionIds,
        array $ownSeriesIds,
        array $ownOrganizationIds,
    ): array {
        $words = self::significantWords($name);

        if ($words === [] && $date === null) {
            return [];
        }

        return [
            ...$this->events($words, $date, $countryCode, $isOnline, $ownCompetitionIds),
            ...$this->series($words, $date, $ownSeriesIds),
            ...$this->organizations($words, $ownOrganizationIds),
        ];
    }

    /**
     * @return list<string>
     */
    public static function significantWords(string $name): array
    {
        $words = array_filter(
            SeriesEditionSearch::words(mb_strtolower($name)),
            static fn (string $word): bool => mb_strlen($word) >= 3 && preg_match('/^\d+$/', $word) !== 1,
        );

        return array_slice(array_values(array_unique($words)), 0, self::MAX_WORDS);
    }

    /**
     * @param list<string> $words
     * @return array{0: string, 1: array<string, string>}
     */
    private static function hits(array $words, string $text): array
    {
        if ($words === []) {
            return ['CAST(0 AS INTEGER)', []];
        }

        $parts = [];
        $parameters = [];

        foreach ($words as $index => $word) {
            $parts[] = "(CASE WHEN {$text} ILIKE immutable_unaccent(:word{$index}) THEN 1 ELSE 0 END)";
            $parameters['word' . $index] = '%' . addcslashes($word, '\\%_') . '%';
        }

        return ['(' . implode(' + ', $parts) . ')', $parameters];
    }

    /**
     * @param list<string> $words
     * @param list<string> $ownIds
     * @return list<PastEventMatch>
     */
    private function events(array $words, null|DateTimeImmutable $date, null|string $countryCode, bool $isOnline, array $ownIds): array
    {
        $visible = IsCompetitionPubliclyVisible::SQL_CONDITION;
        [$hits, $parameters] = self::hits($words, "immutable_unaccent(c.name || ' ' || COALESCE(cs.name, '') || ' ' || COALESCE(c.shortcut, '') || ' ' || COALESCE(cs.shortcut, ''))");
        $days = self::DAYS;

        $near = $date === null
            ? 'false'
            : "(c.date_from IS NOT NULL AND CAST(:day AS DATE) BETWEEN CAST(c.date_from AS DATE) - {$days} AND CAST(COALESCE(c.date_to, c.date_from) AS DATE) + {$days})";
        $samePlace = $isOnline
            ? 'COALESCE(c.is_online, false) = true'
            : ($countryCode !== null ? 'COALESCE(c.location_country_code, cs.location_country_code) = :country' : 'false');
        $distance = $date === null ? 'CAST(NULL AS INTEGER)' : 'ABS(CAST(:day AS DATE) - CAST(c.date_from AS DATE))';
        $allWords = count($words);

        /** @var list<array{id: string, name: string, slug: string, series_id: null|string, series_name: null|string, series_slug: null|string, date_from: null|string, date_to: null|string, location: null|string, country: null|string, is_online: bool, is_public: bool}> $rows */
        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT c.id, c.name, c.slug, c.series_id, cs.name AS series_name, cs.slug AS series_slug, c.date_from, c.date_to,
    COALESCE(c.location, cs.location) AS location,
    COALESCE(c.location_country_code, cs.location_country_code) AS country,
    COALESCE(c.is_online, false) AS is_online,
    {$visible} AS is_public
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
WHERE c.rejected_at IS NULL
    AND (cs.id IS NULL OR cs.rejected_at IS NULL)
    AND ({$visible} OR c.id IN (:own))
    AND (
        ({$near} AND ({$hits} >= 1 OR {$samePlace}))
        OR ({$allWords} > 0 AND {$hits} = {$allWords})
    )
ORDER BY {$near} DESC, {$hits} DESC, {$distance} NULLS LAST, c.date_from DESC NULLS LAST, c.id
LIMIT :limit
SQL, [
            ...$parameters,
            ...($date !== null ? ['day' => $date->format('Y-m-d')] : []),
            ...($countryCode !== null && $isOnline === false ? ['country' => strtolower($countryCode)] : []),
            'own' => $ownIds !== [] ? $ownIds : [self::NO_ID],
            'limit' => self::EVENTS_LIMIT,
        ], ['own' => ArrayParameterType::STRING]);

        return array_map(static fn (array $row): PastEventMatch => new PastEventMatch(
            kind: PastEventMatch::EVENT,
            id: (string) $row['id'],
            name: (string) $row['name'],
            seriesId: $row['series_id'] !== null ? (string) $row['series_id'] : null,
            seriesName: $row['series_name'] !== null ? (string) $row['series_name'] : null,
            dateFrom: $row['date_from'] !== null ? new DateTimeImmutable((string) $row['date_from']) : null,
            dateTo: $row['date_to'] !== null ? new DateTimeImmutable((string) $row['date_to']) : null,
            location: $row['location'] !== null ? (string) $row['location'] : null,
            countryCode: $row['country'] !== null ? CountryCode::fromCode((string) $row['country']) : null,
            isOnline: (bool) $row['is_online'],
            isPublic: (bool) $row['is_public'],
            slug: (string) $row['slug'],
            seriesSlug: $row['series_slug'] !== null ? (string) $row['series_slug'] : null,
        ), $rows);
    }

    /**
     * @param list<string> $words
     * @param list<string> $ownIds
     * @return list<PastEventMatch>
     */
    private function series(array $words, null|DateTimeImmutable $date, array $ownIds): array
    {
        if ($words === []) {
            return [];
        }

        $visible = IsSeriesPubliclyVisible::SQL_CONDITION;
        [$hits, $parameters] = self::hits($words, "immutable_unaccent(cs.name || ' ' || COALESCE(cs.shortcut, ''))");
        $days = self::DAYS;
        $allWords = count($words);
        $nearEdition = $date === null
            ? 'false'
            : "EXISTS (SELECT 1 FROM competition e WHERE e.series_id = cs.id AND e.date_from IS NOT NULL AND CAST(:day AS DATE) BETWEEN CAST(e.date_from AS DATE) - {$days} AND CAST(COALESCE(e.date_to, e.date_from) AS DATE) + {$days})";

        /** @var list<array{id: string, name: string, slug: string, location: null|string, country: null|string, is_online: bool, is_public: bool}> $rows */
        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT cs.id, cs.name, cs.slug, cs.location, cs.location_country_code AS country, cs.is_online, {$visible} AS is_public
FROM competition_series cs
WHERE cs.rejected_at IS NULL
    AND ({$visible} OR cs.id IN (:own))
    AND ({$hits} = {$allWords} OR ({$hits} >= 1 AND {$nearEdition}))
ORDER BY {$hits} DESC, cs.name, cs.id
LIMIT :limit
SQL, [
            ...$parameters,
            ...($date !== null ? ['day' => $date->format('Y-m-d')] : []),
            'own' => $ownIds !== [] ? $ownIds : [self::NO_ID],
            'limit' => self::SERIES_LIMIT,
        ], ['own' => ArrayParameterType::STRING]);

        return array_map(static fn (array $row): PastEventMatch => new PastEventMatch(
            kind: PastEventMatch::SERIES,
            id: (string) $row['id'],
            name: (string) $row['name'],
            seriesId: (string) $row['id'],
            seriesName: (string) $row['name'],
            dateFrom: null,
            dateTo: null,
            location: $row['location'] !== null ? (string) $row['location'] : null,
            countryCode: $row['country'] !== null ? CountryCode::fromCode((string) $row['country']) : null,
            isOnline: (bool) $row['is_online'],
            isPublic: (bool) $row['is_public'],
            slug: (string) $row['slug'],
            seriesSlug: (string) $row['slug'],
        ), $rows);
    }

    /**
     * @param list<string> $words
     * @param list<string> $ownIds
     * @return list<PastEventMatch>
     */
    private function organizations(array $words, array $ownIds): array
    {
        if ($words === []) {
            return [];
        }

        $visible = IsOrganizationPubliclyVisible::SQL_CONDITION;
        [$hits, $parameters] = self::hits($words, "immutable_unaccent(o.name || ' ' || COALESCE(o.short_name, ''))");
        $allWords = count($words);

        /** @var list<array{id: string, name: string, slug: string, country: null|string, is_public: bool}> $rows */
        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT o.id, o.name, o.slug, o.country_code AS country, {$visible} AS is_public
FROM organization o
WHERE o.rejected_at IS NULL
    AND ({$visible} OR o.id IN (:own))
    AND {$hits} = {$allWords}
ORDER BY o.name, o.id
LIMIT :limit
SQL, [
            ...$parameters,
            'own' => $ownIds !== [] ? $ownIds : [self::NO_ID],
            'limit' => self::ORGANIZATIONS_LIMIT,
        ], ['own' => ArrayParameterType::STRING]);

        return array_map(static fn (array $row): PastEventMatch => new PastEventMatch(
            kind: PastEventMatch::ORGANIZATION,
            id: (string) $row['id'],
            name: (string) $row['name'],
            seriesId: null,
            seriesName: null,
            dateFrom: null,
            dateTo: null,
            location: null,
            countryCode: $row['country'] !== null ? CountryCode::fromCode((string) $row['country']) : null,
            isOnline: false,
            isPublic: (bool) $row['is_public'],
            slug: (string) $row['slug'],
            seriesSlug: null,
        ), $rows);
    }
}
