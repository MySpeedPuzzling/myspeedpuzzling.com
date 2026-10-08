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
        // Only for an event that manages registration - the block then is the registration card
        public null|EventRegistration $registration = null,
        // The viewer follows the event, or the series of an edition - the header's labelled star
        public bool $isFollowing = false,
    ) {
    }

    public static function notGoing(): self
    {
        return new self(isGoing: false, canChangeParticipant: false);
    }
}
