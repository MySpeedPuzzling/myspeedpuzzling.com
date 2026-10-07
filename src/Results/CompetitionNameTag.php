<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

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
    ) {
    }
}
