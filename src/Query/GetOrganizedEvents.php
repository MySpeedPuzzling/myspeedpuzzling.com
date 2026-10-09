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
use SpeedPuzzling\Web\Results\UnpublishCheck;
use SpeedPuzzling\Web\Services\Drafts\UnpublishBlockers;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\OccurrenceDates;
use SpeedPuzzling\Web\Value\RoundTimezone;

/**
 * The items of "You organize" (docs/features/events-page/implementation-plan.md, 1.4) for the ids of
 * EventsViewerData::organizedCompetitionIds() / organizedSeriesIds() / organizedOrganizationIds(): a statement per
 * kind asked for, drafts, waiting for approval and rejected ones included. Dates follow the occurrence rule
 * (OccurrenceDates); of a competition whose rounds fall on separate days, the session not over yet (else the last)
 * stands for it. "Approved" is the approval state alone (IsCompetitionPubliclyVisible::SQL_APPROVED's meaning) - a draft
 * says so on its own (docs/features/organizations/README.md).
 */
readonly final class GetOrganizedEvents
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $competitionIds
     * @param list<string> $seriesIds
     * @param list<string> $organizationIds
     *
     * @return list<OrganizedEvent>
     */
    public function byIds(array $competitionIds, array $seriesIds, array $organizationIds = []): array
    {
        return [
            ...$this->organizations(self::validIds($organizationIds)),
            ...$this->competitions(self::validIds($competitionIds)),
            ...$this->series(self::validIds($seriesIds)),
        ];
    }

    /**
     * @param list<string> $ids
     *
     * @return list<OrganizedEvent>
     */
    private function organizations(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $query = <<<SQL
SELECT o.id, o.name, o.slug, o.country_code, o.is_draft,
    (o.approved_at IS NOT NULL) AS is_approved, (o.rejected_at IS NOT NULL) AS is_rejected, o.rejection_reason,
    (SELECT COUNT(*) FROM competition_series cs WHERE cs.organization_id = o.id) AS series_count,
    (SELECT COUNT(*) FROM competition c WHERE c.organization_id = o.id AND c.series_id IS NULL) AS event_count
FROM organization o
WHERE o.id IN (:ids)
SQL;

        $items = [];

        /** @var array<string, null|string|int|bool> $row */

        foreach ($this->database->executeQuery($query, ['ids' => $ids], ['ids' => ArrayParameterType::STRING])->fetchAllAssociative() as $row) {
            $items[] = new OrganizedEvent(
                kind: OrganizedEvent::KIND_ORGANIZATION,
                id: (string) $row['id'],
                name: (string) $row['name'],
                slug: self::string($row['slug']),
                countryCode: CountryCode::fromCode(self::string($row['country_code'])),
                isApproved: (bool) $row['is_approved'],
                rejectionReason: self::string($row['rejection_reason']),
                isRejected: (bool) $row['is_rejected'],
                isDraft: (bool) $row['is_draft'],
                seriesCount: is_numeric($row['series_count']) ? (int) $row['series_count'] : 0,
                eventCount: is_numeric($row['event_count']) ? (int) $row['event_count'] : 0,
                ownDraft: (bool) $row['is_draft'],
            );
        }

        return $items;
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

        $rounds = OccurrenceRounds::SQL_JOIN;
        // What keeps it from going back to draft - its row offers Unpublish only when nothing does (as the ⋯ menu)
        $blocking = UnpublishBlockers::sqlColumns('c.id');

        $query = <<<SQL
SELECT c.id, c.name, c.slug, c.series_id, cs.name AS series_name, cs.slug AS series_slug,
    {$blocking},
    CASE WHEN c.series_id IS NULL THEN c.is_online ELSE cs.is_online END AS is_online,
    COALESCE(c.location, cs.location) AS location,
    COALESCE(c.location_country_code, cs.location_country_code) AS country_code,
    c.location_country_code AS own_country_code, cs.location_country_code AS series_country_code,
    c.date_from, c.date_to, r.rounds, COALESCE(r.round_count, 0) AS round_count,
    CASE WHEN c.series_id IS NULL THEN c.approved_at IS NOT NULL ELSE cs.approved_at IS NOT NULL END AS is_approved,
    (c.rejected_at IS NOT NULL OR cs.rejected_at IS NOT NULL) AS is_rejected,
    COALESCE(c.rejection_reason, cs.rejection_reason) AS rejection_reason,
    c.is_draft AS own_draft, COALESCE(cs.is_draft, false) AS series_is_draft,
    COALESCE(c.organization_id, cs.organization_id) AS organization_id
FROM competition c
LEFT JOIN competition_series cs ON cs.id = c.series_id
{$rounds}
WHERE c.id IN (:ids)
SQL;

        $today = $this->clock->now();
        $items = [];

        /** @var array<string, null|string|int|bool> $row */

        foreach ($this->database->executeQuery($query, ['ids' => $ids], ['ids' => ArrayParameterType::STRING])->fetchAllAssociative() as $row) {
            $isEdition = $row['series_id'] !== null;
            // Rounds on separate days: the session that is next (or the last one) stands for the competition
            $dates = OccurrenceDates::current($this->sessions($row), $today, $isEdition, (bool) $row['is_online']);

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
                lastRoundDay: $dates->lastRoundDay,
                zone: $dates->zone(),
                isApproved: (bool) $row['is_approved'],
                rejectionReason: self::string($row['rejection_reason']),
                isRejected: (bool) $row['is_rejected'],
                isDraft: (bool) $row['own_draft'] || (bool) $row['series_is_draft'],
                organizationId: self::string($row['organization_id']),
                ownDraft: (bool) $row['own_draft'],
                seriesIsDraft: (bool) $row['series_is_draft'],
                unpublishBlockers: UnpublishBlockers::checkOf($row)->blockers(),
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

        $rounds = OccurrenceRounds::SQL_JOIN;
        // Per edition - summed per series: a series goes back to draft only when none of its editions holds anything
        $blocking = UnpublishBlockers::sqlColumns('c.id');

        $query = <<<SQL
SELECT cs.id AS series_id, cs.name AS series_name, cs.slug AS series_slug, cs.is_online, cs.location,
    {$blocking},
    cs.location_country_code AS series_country_code,
    (cs.approved_at IS NOT NULL) AS is_approved, (cs.rejected_at IS NOT NULL) AS is_rejected, cs.rejection_reason,
    cs.is_draft, cs.organization_id,
    c.id AS edition_id, c.location_country_code AS own_country_code, c.date_from, c.date_to,
    r.rounds, COALESCE(r.round_count, 0) AS round_count
FROM competition_series cs
LEFT JOIN competition c ON c.series_id = cs.id
{$rounds}
WHERE cs.id IN (:ids)
SQL;

        $now = $this->clock->now();
        /** @var array<string, array{row: array<string, null|string|int|bool>, count: int, next: null|DateTimeImmutable, nextZone: null|string, last: null|DateTimeImmutable, blocking: array{0: int, 1: int, 2: int}}> $series */
        $series = [];

        /** @var array<string, null|string|int|bool> $row */

        foreach ($this->database->executeQuery($query, ['ids' => $ids], ['ids' => ArrayParameterType::STRING])->fetchAllAssociative() as $row) {
            $id = (string) $row['series_id'];
            $series[$id] ??= ['row' => $row, 'count' => 0, 'next' => null, 'nextZone' => null, 'last' => null, 'blocking' => [0, 0, 0]];

            if ($row['edition_id'] === null) {
                continue;
            }

            $check = UnpublishBlockers::checkOf($row);
            $series[$id]['blocking'] = [
                $series[$id]['blocking'][0] + $check->participants,
                $series[$id]['blocking'][1] + $check->results,
                $series[$id]['blocking'][2] + $check->solvingTimes,
            ];

            $series[$id]['count']++;

            foreach ($this->sessions($row) as $dates) {
                if ($dates->start === null) {
                    continue;
                }

                if ($dates->status($now, true, (bool) $row['is_online']) === EventOccurrenceStatus::Past) {
                    if ($series[$id]['last'] === null || $dates->start > $series[$id]['last']) {
                        $series[$id]['last'] = $dates->start;
                    }
                } elseif ($series[$id]['next'] === null || $dates->start < $series[$id]['next']) {
                    $series[$id]['next'] = $dates->start;
                    $series[$id]['nextZone'] = $dates->zone();
                }
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
                nextEditionZone: $item['nextZone'],
                lastEditionDate: $item['last'],
                isDraft: (bool) $row['is_draft'],
                organizationId: self::string($row['organization_id']),
                ownDraft: (bool) $row['is_draft'],
                unpublishBlockers: new UnpublishCheck(...$item['blocking'])->blockers(),
            );
        }

        return $items;
    }

    /**
     * @param array<string, null|string|int|bool> $row
     *
     * @return non-empty-list<OccurrenceDates>
     */
    private function sessions(array $row): array
    {
        return OccurrenceDates::sessions(
            self::instant($row['date_from']),
            self::instant($row['date_to']),
            OccurrenceRounds::fromJson($row['rounds'], self::string($row['own_country_code']), self::string($row['series_country_code'] ?? null)),
            RoundTimezone::resolve(null, self::string($row['own_country_code']), self::string($row['series_country_code'] ?? null)),
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
