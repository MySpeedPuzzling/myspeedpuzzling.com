<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

/**
 * A person on a printed line-up or round entry tag (GetCompetitionLineupTags, GetRoundEntryTags): the participant as the
 * organiser recorded them, the linked player's code only when their profile is public (the name tags' rule - a tag is
 * seen in public, whoever prints it). The profile's country follows the same rule; it fills a missing country only when
 * the organiser asks for large country flags (flagCountry(), competitor-pages.md "Country flags").
 */
readonly final class CompetitionTagMember
{
    public function __construct(
        public string $participantId,
        public string $name,
        public null|string $country,
        public null|string $playerCode,
        public null|string $profileCountry = null,
    ) {
    }

    /**
     * @param array{id: string, name: string, country: null|string, code: null|string, profile_country: null|string} $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row['id'], $row['name'], $row['country'], $row['code'], $row['profile_country']);
    }

    /**
     * The flag printed with "Show country flags": the country on the participant list, else the linked public profile's.
     */
    public function flagCountry(): null|string
    {
        return $this->country ?? CountryCode::fromCode($this->profileCountry)?->name;
    }
}
