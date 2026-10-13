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
        // An online competition with a round using online timing (docs/features/online-competitions/): the participant
        // the viewer is connected to and is going as, and their personal link's token - "Open my competition page".
        // Null otherwise (and never read for an in-person event)
        public null|string $onlineParticipantId = null,
        public null|string $onlineToken = null,
    ) {
    }

    public static function notGoing(): self
    {
        return new self(isGoing: false, canChangeParticipant: false);
    }
}
