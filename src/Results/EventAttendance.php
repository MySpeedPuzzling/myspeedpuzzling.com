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
        // The participant (the organiser's name for them) the viewer asked to be linked to, waiting for the organiser
        // (docs/features/competitions-management/participant-linking.md §6.1) - not going yet (D4)
        public null|string $pendingRequestParticipantName = null,
        // "Is this you?" (participant-linking.md §6.1): the one active unlinked participant of the event with the viewer's
        // name and a compatible country - or the one a scanned name tag asked for (`?participant=`, §6.4). Only for a
        // signed-in viewer who is not going and asks nothing yet, on a publicly visible event
        public null|ParticipantClaimCandidate $claimCandidate = null,
    ) {
    }

    public static function notGoing(): self
    {
        return new self(isGoing: false, canChangeParticipant: false);
    }
}
