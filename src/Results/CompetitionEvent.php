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
 *     is_recurring: bool|string,
 *     added_by_player_id: null|string,
 *     approved_at: null|string,
 *     rejected_at: null|string,
 *     rejection_reason: null|string,
 *     created_at: null|string,
 *     event_status?: null|string,
 *     sort_date?: null|string,
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
        public bool $isRecurring,
        public null|string $addedByPlayerId,
        public null|DateTimeImmutable $approvedAt,
        public null|DateTimeImmutable $rejectedAt,
        public null|string $rejectionReason,
        public null|DateTimeImmutable $createdAt,
        public null|string $eventStatus = null,
    ) {
        $this->link = $this->appendUtm($link);
        $this->registrationLink = $this->appendUtm($registrationLink);
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

        $isRecurring = $row['is_recurring'];
        if (is_string($isRecurring)) {
            $isRecurring = $isRecurring === 't' || $isRecurring === '1' || $isRecurring === 'true';
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
            isRecurring: $isRecurring,
            addedByPlayerId: $row['added_by_player_id'],
            approvedAt: $row['approved_at'] !== null ? new DateTimeImmutable($row['approved_at']) : null,
            rejectedAt: $row['rejected_at'] !== null ? new DateTimeImmutable($row['rejected_at']) : null,
            rejectionReason: $row['rejection_reason'],
            createdAt: $row['created_at'] !== null ? new DateTimeImmutable($row['created_at']) : null,
            eventStatus: $row['event_status'] ?? null,
        );
    }


    private function appendUtm(null|string $link): null|string
    {
        if ($link === null) {
            return null;
        }

        return $link . (str_contains($link, '?') ? '&' : '?') . 'utm_source=myspeedpuzzling';
    }
}
