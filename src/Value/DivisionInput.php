<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One division of an event as the organiser saves it (SetCompetitionDivisions): an id of the event's division to keep,
 * or a new id for a new one; the name and short label as typed (cleaned by CompetitionDivision).
 */
readonly final class DivisionInput
{
    public function __construct(
        public string $id,
        public string $name,
        public null|string $shortName = null,
    ) {
    }
}
