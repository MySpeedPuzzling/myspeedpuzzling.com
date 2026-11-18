<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

/**
 * One printed name tag (GetCompetitionNameTags): the participant as the organiser recorded them, the linked player's
 * code (public profiles only), and the table number of their first round (or of the chosen round) - null when that round has none or does
 * not use table numbers.
 */
readonly final class CompetitionNameTag
{
    public function __construct(
        public string $participantId,
        public string $name,
        public null|string $country,
        public null|string $playerCode,
        public null|int $tableNumber,
        public null|string $roundName,
        // On the waitlist - printed only when the organiser asks for the waitlist too
        public bool $waitlisted = false,
        // The division label of the entry whose table is printed (shared-puzzles-and-divisions.md D5) - null without one
        public null|string $division = null,
        // The linked player's own country, public profiles only - fills a missing country with "Show country flags"
        public null|string $profileCountry = null,
    ) {
    }

    /**
     * The flag printed with "Show country flags" (competitor-pages.md "Country flags"): the country on the participant
     * list, else the linked public profile's.
     */
    public function flagCountry(): null|string
    {
        return $this->country ?? CountryCode::fromCode($this->profileCountry)?->name;
    }
}
