<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * @phpstan-type CompetitionEventDatabaseRow array{
 *     id: string,
 *     name: string,
 *     location: string,
 *     location_country_code: null|string,
 *     date_from: string,
 *     date_to: null|string,
 *     logo: null|string,
 *     description: null|string,
 *     link: null|string,
 *     registration_link: null|string,
 *     results_link: null|string,
 *     slug: null|string,
 *     tag_id: null|string,
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
        public null|string $logo,
        public null|string $description,
        null|string $link,
        null|string $registrationLink,
        null|string $resultsLink,
        public string $location,
        public null|CountryCode $locationCountryCode,
        public DateTimeImmutable $dateFrom,
        public null|DateTimeImmutable $dateTo,
        public null|string $slug,
        public null|string $tagId,
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
        return new self(
            id: $row['id'],
            name: $row['name'],
            logo: $row['logo'],
            description: $row['description'],
            link: $row['link'],
            registrationLink: $row['registration_link'],
            resultsLink: $row['results_link'],
            location: $row['location'],
            locationCountryCode: $row['location_country_code'] !== null ? CountryCode::fromCode($row['location_country_code']) : null,
            dateFrom: (new DateTimeImmutable($row['date_from']))->setTime(9, 0),
            dateTo: $row['date_to'] !== null ? new DateTimeImmutable($row['date_to']) : null,
            slug: $row['slug'],
            tagId: $row['tag_id'],
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
