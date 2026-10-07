<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * @phpstan-type CompetitionEventDatabaseRow array{
 *     id: string,
 *     name: string,
 *     shortcut: null|string,
 *     location: null|string,
 *     location_country_code: null|string,
 *     date_from: null|string,
 *     date_to: null|string,
 *     logo: null|string,
 *     description: null|string,
 *     link: null|string,
 *     registration_link: null|string,
 *     results_link: null|string,
 *     slug: null|string,
 *     tag_id: null|string,
 *     is_online: bool|string,
 *     series_id: null|string,
 *     added_by_player_id: null|string,
 *     approved_at: null|string,
 *     rejected_at: null|string,
 *     rejection_reason: null|string,
 *     created_at: null|string,
 *     event_status?: null|string,
 *     sort_date?: null|string,
 *     added_by_player_name?: null|string,
 *     registration_managed?: bool|string,
 *     capacity?: null|int|string,
 *     registration_opens_at?: null|string,
 *     registration_closes_at?: null|string,
 *     entry_fee_text?: null|string,
 *     payment_instructions?: null|string,
 *     registration_timezone?: null|string,
 *     has_page_sections?: bool|string,
 * }
 */
readonly final class CompetitionEvent
{
    public null|string $link;
    public null|string $registrationLink;
    public null|string $resultsLink;

    public function __construct(
        public string $id,
        public string $name,
        public null|string $shortcut,
        public null|string $logo,
        public null|string $description,
        null|string $link,
        null|string $registrationLink,
        null|string $resultsLink,
        public null|string $location,
        public null|CountryCode $locationCountryCode,
        public null|DateTimeImmutable $dateFrom,
        public null|DateTimeImmutable $dateTo,
        public null|string $slug,
        public null|string $tagId,
        public bool $isOnline,
        public null|string $seriesId,
        public null|string $addedByPlayerId,
        public null|DateTimeImmutable $approvedAt,
        public null|DateTimeImmutable $rejectedAt,
        public null|string $rejectionReason,
        public null|DateTimeImmutable $createdAt,
        public null|string $eventStatus = null,
        public null|string $addedByPlayerName = null,
        public bool $registrationManaged = false,
        public null|int $capacity = null,
        public null|DateTimeImmutable $registrationOpensAt = null,
        public null|DateTimeImmutable $registrationClosesAt = null,
        public null|string $entryFeeText = null,
        public null|string $paymentInstructions = null,
        public null|string $registrationTimezone = null,
        // An organiser-written section shows on the page (GetCompetitionPageSections) - only GetCompetitionEvents::byId()
        // reads it, so the event and edition pages query sections only when there is one to show
        public bool $hasPageSections = false,
    ) {
        $this->link = $this->appendUtm($link);
        // While the event manages registration on MySpeedPuzzling the external link stays saved but is not shown -
        // one way to register (docs/features/competitions-management/registration.md)
        $this->registrationLink = $registrationManaged ? null : $this->appendUtm($registrationLink);
        $this->resultsLink = $this->appendUtm($resultsLink);
    }

    /**
     * @param CompetitionEventDatabaseRow $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $isOnline = $row['is_online'];
        if (is_string($isOnline)) {
            $isOnline = $isOnline === 't' || $isOnline === '1' || $isOnline === 'true';
        }

        return new self(
            id: $row['id'],
            name: $row['name'],
            shortcut: $row['shortcut'],
            logo: $row['logo'],
            description: $row['description'],
            link: $row['link'],
            registrationLink: $row['registration_link'],
            resultsLink: $row['results_link'],
            location: $row['location'],
            locationCountryCode: $row['location_country_code'] !== null ? CountryCode::fromCode($row['location_country_code']) : null,
            dateFrom: $row['date_from'] !== null ? new DateTimeImmutable($row['date_from'])->setTime(9, 0) : null,
            dateTo: $row['date_to'] !== null ? new DateTimeImmutable($row['date_to']) : null,
            slug: $row['slug'],
            tagId: $row['tag_id'],
            isOnline: $isOnline,
            seriesId: $row['series_id'],
            addedByPlayerId: $row['added_by_player_id'],
            approvedAt: $row['approved_at'] !== null ? new DateTimeImmutable($row['approved_at']) : null,
            rejectedAt: $row['rejected_at'] !== null ? new DateTimeImmutable($row['rejected_at']) : null,
            rejectionReason: $row['rejection_reason'],
            createdAt: $row['created_at'] !== null ? new DateTimeImmutable($row['created_at']) : null,
            eventStatus: $row['event_status'] ?? null,
            addedByPlayerName: $row['added_by_player_name'] ?? null,
            registrationManaged: self::parseBool($row['registration_managed'] ?? false),
            capacity: isset($row['capacity']) ? (int) $row['capacity'] : null,
            registrationOpensAt: isset($row['registration_opens_at']) ? new DateTimeImmutable($row['registration_opens_at']) : null,
            registrationClosesAt: isset($row['registration_closes_at']) ? new DateTimeImmutable($row['registration_closes_at']) : null,
            entryFeeText: $row['entry_fee_text'] ?? null,
            paymentInstructions: $row['payment_instructions'] ?? null,
            registrationTimezone: $row['registration_timezone'] ?? null,
            hasPageSections: self::parseBool($row['has_page_sections'] ?? false),
        );
    }

    /**
     * Whether the event only starts on a later calendar day than the given one — i.e. it is still
     * "upcoming" on that day. The start is COALESCE(date_from, date_to), the same rule the event lists
     * classify by; an undated event is perpetual and never upcoming. Compared by calendar day on purpose:
     * `fromDatabaseRow` pins dateFrom to 09:00, so datetimes would disagree with the SQL `::date` rule.
     */
    public function startsAfter(DateTimeImmutable $day): bool
    {
        $start = $this->dateFrom ?? $this->dateTo;

        if ($start === null) {
            return false;
        }

        return $start->format('Y-m-d') > $day->format('Y-m-d');
    }

    /**
     * The day of a one-day event - its round form asks only for the time. Null when the event spans days or is undated.
     */
    public function singleDay(): null|DateTimeImmutable
    {
        if ($this->dateFrom === null || $this->dateTo === null) {
            return null;
        }

        return $this->dateFrom->format('Y-m-d') === $this->dateTo->format('Y-m-d') ? $this->dateFrom : null;
    }

    private static function parseBool(bool|string $value): bool
    {
        if (is_string($value)) {
            return $value === 't' || $value === '1' || $value === 'true';
        }

        return $value;
    }

    private function appendUtm(null|string $link): null|string
    {
        if ($link === null) {
            return null;
        }

        return $link . (str_contains($link, '?') ? '&' : '?') . 'utm_source=myspeedpuzzling';
    }
}
