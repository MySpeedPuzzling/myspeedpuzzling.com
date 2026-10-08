<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\OrganizedEvent;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\OccurrenceDates;
use SpeedPuzzling\Web\Value\RoundTimezone;

/**
 * The items of "You organize" (docs/features/events-page/implementation-plan.md, 1.4) for the ids of
 * EventsViewerData::organizedCompetitionIds() / organizedSeriesIds(): two statements, waiting for approval and
 * rejected ones included. Dates follow the occurrence rule (OccurrenceDates).
 */
readonly final class GetOrganizedEvents
{
    private const string ROUNDS_JOIN = <<<SQL
LEFT JOIN (
    SELECT competition_id,
        MIN(starts_at) AS first_starts_at,
        MAX(starts_at) AS last_starts_at,
        MIN(timezone) AS round_timezone,
        COUNT(*) AS round_count
    FROM competition_round
    GROUP BY competition_id
) r ON r.competition_id = c.id
SQL;

    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $competitionIds
     * @param list<string> $seriesIds
     *
     * @return list<OrganizedEvent>
     */
    public function byIds(array $competitionIds, array $seriesIds): array
    {
        return [
            ...$this->competitions(self::validIds($competitionIds)),
            ...$this->series(self::validIds($seriesIds)),
        ];
    }

    /**
     * @param list<string> $ids
     *
     * @return list<OrganizedEvent>
     */
    private function competitions(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rounds = self::ROUNDS_JOIN;

        $query = <<<SQL
SELECT c.id, c.name, c.slug, c.series_id, cs.name AS series_name, cs.slug AS series_slug,
    CASE WHEN c.series_id IS NULL THEN c.is_online ELSE cs.is_online END AS is_online,
    COALESCE(c.location, cs.location) AS location,
    COALESCE(c.location_country_code, cs.location_country_code) AS country_code,
    c.location_country_code AS own_country_code, cs.location_country_code AS series_country_code,
    c.date_from, c.date_to, r.first_starts_at, r.last_starts_at, r.round_timezone, COALESCE(r.round_count, 0) AS round_count,
    CASE WHEN c.series_id IS NULL THEN c.approved_at IS NOT NULL ELSE cs.approved_at IS NOT NULL END AS is_approved,
    (c.rejected_at IS NOT NULL OR cs.rejected_at IS NOT NULL) AS is_rejected,
    COALESCE(c.rejection_reason, cs.rejection_reason) AS rejection_reason
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
{$rounds}
WHERE c.id IN (:ids)
SQL;

        $items = [];

        /** @var array<string, null|string|int|bool> $row */

        foreach ($this->database->executeQuery($query, ['ids' => $ids], ['ids' => ArrayParameterType::STRING])->fetchAllAssociative() as $row) {
            $isEdition = $row['series_id'] !== null;
            $dates = $this->dates($row, $isEdition);

            $items[] = new OrganizedEvent(
                kind: $isEdition ? OrganizedEvent::KIND_EDITION : OrganizedEvent::KIND_EVENT,
                id: (string) $row['id'],
                name: (string) $row['name'],
                seriesId: self::string($row['series_id']),
                seriesName: self::string($row['series_name']),
                slug: self::string($row['slug']),
                seriesSlug: self::string($row['series_slug']),
                isOnline: (bool) $row['is_online'],
                location: self::string($row['location']),
                countryCode: CountryCode::fromCode(self::string($row['country_code'])),
                startDate: $dates->start,
                endDate: $dates->end,
                roundCount: is_numeric($row['round_count']) ? (int) $row['round_count'] : 0,
                isApproved: (bool) $row['is_approved'],
                rejectionReason: self::string($row['rejection_reason']),
                isRejected: (bool) $row['is_rejected'],
            );
        }

        return $items;
    }

    /**
     * One row per series and edition (a series without editions: one row with no edition), grouped here.
     *
     * @param list<string> $ids
     *
     * @return list<OrganizedEvent>
     */
    private function series(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rounds = self::ROUNDS_JOIN;

        $query = <<<SQL
SELECT cs.id AS series_id, cs.name AS series_name, cs.slug AS series_slug, cs.is_online, cs.location,
    cs.location_country_code AS series_country_code,
    (cs.approved_at IS NOT NULL) AS is_approved, (cs.rejected_at IS NOT NULL) AS is_rejected, cs.rejection_reason,
    c.id AS edition_id, c.location_country_code AS own_country_code, c.date_from, c.date_to,
    r.first_starts_at, r.last_starts_at, r.round_timezone
FROM competition_series cs
LEFT JOIN competition c ON c.series_id = cs.id
{$rounds}
WHERE cs.id IN (:ids)
SQL;

        $today = OccurrenceDates::today($this->clock->now());
        /** @var array<string, array{row: array<string, null|string|int|bool>, count: int, next: null|DateTimeImmutable, last: null|DateTimeImmutable}> $series */
        $series = [];

        /** @var array<string, null|string|int|bool> $row */

        foreach ($this->database->executeQuery($query, ['ids' => $ids], ['ids' => ArrayParameterType::STRING])->fetchAllAssociative() as $row) {
            $id = (string) $row['series_id'];
            $series[$id] ??= ['row' => $row, 'count' => 0, 'next' => null, 'last' => null];

            if ($row['edition_id'] === null) {
                continue;
            }

            $series[$id]['count']++;
            $dates = $this->dates($row, true);
            $status = $dates->status($today, true, (bool) $row['is_online']);

            if ($dates->start === null) {
                continue;
            }

            if ($status === EventOccurrenceStatus::Past) {
                if ($series[$id]['last'] === null || $dates->start > $series[$id]['last']) {
                    $series[$id]['last'] = $dates->start;
                }
            } elseif ($series[$id]['next'] === null || $dates->start < $series[$id]['next']) {
                $series[$id]['next'] = $dates->start;
            }
        }

        $items = [];

        foreach ($series as $id => $item) {
            $row = $item['row'];

            $items[] = new OrganizedEvent(
                kind: OrganizedEvent::KIND_SERIES,
                id: $id,
                name: (string) $row['series_name'],
                seriesId: $id,
                seriesName: (string) $row['series_name'],
                slug: self::string($row['series_slug']),
                seriesSlug: self::string($row['series_slug']),
                isOnline: (bool) $row['is_online'],
                location: self::string($row['location']),
                countryCode: CountryCode::fromCode(self::string($row['series_country_code'])),
                isApproved: (bool) $row['is_approved'],
                rejectionReason: self::string($row['rejection_reason']),
                isRejected: (bool) $row['is_rejected'],
                editionCount: $item['count'],
                nextEditionDate: $item['next'],
                lastEditionDate: $item['last'],
            );
        }

        return $items;
    }

    /**
     * @param array<string, null|string|int|bool> $row
     */
    private function dates(array $row, bool $isEdition): OccurrenceDates
    {
        if ($isEdition === false) {
            return OccurrenceDates::ofEvent(self::instant($row['date_from']), self::instant($row['date_to']));
        }

        return OccurrenceDates::ofEdition(
            self::instant($row['first_starts_at']),
            self::instant($row['last_starts_at']),
            RoundTimezone::resolve(self::string($row['round_timezone']), self::string($row['own_country_code']), self::string($row['series_country_code'])),
            self::instant($row['date_from']),
            self::instant($row['date_to']),
        );
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private static function validIds(array $ids): array
    {
        return array_values(array_unique(array_map(strtolower(...), array_filter($ids, Uuid::isValid(...)))));
    }

    private static function string(mixed $value): null|string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function instant(mixed $value): null|DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value, new DateTimeZone('UTC')) : null;
    }
}
