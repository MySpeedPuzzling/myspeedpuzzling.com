<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\EventOccurrence;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\OccurrenceDates;
use SpeedPuzzling\Web\Value\OccurrenceRound;

/**
 * Every occurrence of the events page in one statement (docs/features/events-page/implementation-plan.md, 1.4):
 * one-time events and editions together, one per session when a competition's rounds fall on separate days
 * (OccurrenceDates::sessions()). Admins also get the ones waiting for approval (`isPublic` false); rejected ones, and
 * editions of a rejected series, nobody.
 *
 * forSeries() - the series page (docs/features/events-page/detail-pages.md): every edition of one series, the same
 * rows, in one statement.
 *
 * Results: a competition has them with a results link, or when one of its rounds has a time or published official
 * results (OccurrenceRounds::SQL_JOIN_WITH_RESULTS); a session of several has them with the link or through its own
 * rounds only.
 */
readonly final class GetEventOccurrences
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<EventOccurrence>
     */
    public function all(bool $includeUnapproved): array
    {
        $where = $includeUnapproved
            ? 'c.rejected_at IS NULL AND (c.series_id IS NULL OR cs.rejected_at IS NULL)'
            : IsCompetitionPubliclyVisible::SQL_CONDITION;

        return $this->fetch($where, []);
    }

    /**
     * Every edition of the series but the rejected ones - also of a series not approved (yet): its own page lists its
     * editions as before, `isPublic` says what is public. A rejected series still lists its editions here (its page is
     * reachable at its URL, `noindex`), never on the events page.
     *
     * @return list<EventOccurrence>
     */
    public function forSeries(string $seriesId): array
    {
        if (Uuid::isValid($seriesId) === false) {
            return [];
        }

        // The rounds aggregate only over the series' own competitions - the page must not grow with the site
        return $this->fetch(
            'c.series_id = :seriesId AND c.rejected_at IS NULL',
            ['seriesId' => $seriesId],
            'WHERE cr_j.competition_id IN (SELECT s_c.id FROM competition s_c WHERE s_c.series_id = :seriesId)',
        );
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return list<EventOccurrence>
     */
    private function fetch(string $where, array $parameters, string $roundsWhere = ''): array
    {
        $visible = IsCompetitionPubliclyVisible::SQL_CONDITION;
        $rounds = OccurrenceRounds::sqlJoinWithResults($roundsWhere);

        $query = <<<SQL
SELECT
    c.id,
    c.name,
    c.slug,
    c.logo,
    c.series_id,
    cs.name AS series_name,
    cs.slug AS series_slug,
    COALESCE(c.location, cs.location) AS location,
    COALESCE(c.location_country_code, cs.location_country_code) AS country_code,
    c.location_country_code AS own_country_code,
    cs.location_country_code AS series_country_code,
    CASE WHEN c.series_id IS NULL THEN c.is_online ELSE cs.is_online END AS is_online,
    c.date_from,
    c.date_to,
    r.rounds,
    COALESCE(r.round_count, 0) AS round_count,
    (c.registration_link IS NOT NULL AND c.registration_managed = false) AS has_registration_link,
    CASE WHEN c.registration_managed THEN NULL ELSE c.registration_link END AS registration_link,
    c.registration_managed,
    c.capacity,
    c.registration_opens_at,
    c.registration_closes_at,
    c.registration_timezone,
    (c.results_link IS NOT NULL) AS has_results_link,
    ({$visible}) AS is_public
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
{$rounds}
WHERE {$where}
SQL;

        $occurrences = [];

        /** @var array<string, null|string|int|bool> $row */

        foreach ($this->database->executeQuery($query, $parameters)->fetchAllAssociative() as $row) {
            array_push($occurrences, ...self::hydrate($row));
        }

        usort($occurrences, static function (EventOccurrence $a, EventOccurrence $b): int {
            if ($a->startDate === null || $b->startDate === null) {
                return ($a->startDate === null) <=> ($b->startDate === null) ?: strcmp($a->name, $b->name);
            }

            return $a->startDate <=> $b->startDate ?: strcmp($a->name, $b->name);
        });

        return $occurrences;
    }

    /**
     * @param array<string, null|string|int|bool> $row
     *
     * @return non-empty-list<EventOccurrence>
     */
    private static function hydrate(array $row): array
    {
        $ownCountry = self::nullableString($row['own_country_code']);
        $seriesCountry = self::nullableString($row['series_country_code']);
        $rounds = OccurrenceRounds::fromJson($row['rounds'], $ownCountry, $seriesCountry);
        $hasResultsLink = (bool) $row['has_results_link'];
        $competitionHasResults = $hasResultsLink || array_any($rounds, static fn (OccurrenceRound $round): bool => $round->hasResults);

        $sessions = OccurrenceDates::sessions(
            self::instant($row['date_from']),
            self::instant($row['date_to']),
            $rounds,
        );

        $capacity = $row['capacity'];

        return array_map(static fn (OccurrenceDates $dates): EventOccurrence => new EventOccurrence(
            competitionId: (string) $row['id'],
            name: (string) $row['name'],
            slug: self::nullableString($row['slug']),
            logo: self::nullableString($row['logo']),
            seriesId: self::nullableString($row['series_id']),
            seriesName: self::nullableString($row['series_name']),
            seriesSlug: self::nullableString($row['series_slug']),
            location: self::nullableString($row['location']),
            countryCode: CountryCode::fromCode(self::nullableString($row['country_code'])),
            isOnline: (bool) $row['is_online'],
            startDate: $dates->start,
            endDate: $dates->end,
            roundCount: is_numeric($row['round_count']) ? (int) $row['round_count'] : 0,
            hasRegistrationLink: (bool) $row['has_registration_link'],
            registrationManaged: (bool) $row['registration_managed'],
            capacity: is_numeric($capacity) ? (int) $capacity : null,
            registrationOpensAt: self::instant($row['registration_opens_at']),
            registrationClosesAt: self::instant($row['registration_closes_at']),
            registrationTimezone: self::nullableString($row['registration_timezone']),
            // A session of several: its own rounds' results (or the results link of the whole competition)
            hasResults: $dates->session !== null ? ($hasResultsLink || $dates->session->hasResults) : $competitionHasResults,
            isPublic: (bool) $row['is_public'],
            session: $dates->session,
            lastRoundDay: $dates->lastRoundDay,
            firstRound: $dates->firstRound,
            registrationLink: self::withUtm(self::nullableString($row['registration_link'])),
        ), $sessions);
    }

    /**
     * Every external link carries utm_source=myspeedpuzzling (as CompetitionEvent's)
     */
    private static function withUtm(null|string $link): null|string
    {
        return $link !== null ? $link . (str_contains($link, '?') ? '&' : '?') . 'utm_source=myspeedpuzzling' : null;
    }

    private static function nullableString(mixed $value): null|string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A stored timestamp (UTC, without zone)
     */
    private static function instant(mixed $value): null|DateTimeImmutable
    {
        if (is_string($value) === false || $value === '') {
            return null;
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
