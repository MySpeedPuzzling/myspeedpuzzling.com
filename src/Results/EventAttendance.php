<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Whether the viewer is going to a competition - the "I'm going" block of a standalone event and of a series
 * edition (templates/_event_attendance.html.twig).
 */
readonly final class EventAttendance
{
    public function __construct(
        public bool $isGoing,
        // "Change" only makes sense while the organizer's list still has someone to switch to
        public bool $canChangeParticipant,
    ) {
    }

    public static function notGoing(): self
    {
        return new self(isGoing: false, canChangeParticipant: false);
    }
}
