<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The host's "Thank you" (contract phase-1b.md §4): the guest's invitation ends (`ended` + `onAirTo`) - one not
 * answered yet is withdrawn. The handler answers EndedStudioGuest (HandledStamp); when the guest was on air, the
 * controller then takes their publishing away in the studio (StudioLiveUpdates::ended(), LiveKit UpdateParticipant).
 * Ending an invitation that is over already changes nothing.
 *
 * The invitation's row is locked for the write (StudioInviteRepository::getForUpdate()).
 */
readonly final class EndStudioGuest
{
    public function __construct(
        public string $inviteId,
        // The host - authorised for the invitation's round by the controller
        public string $actingPlayerId,
    ) {
    }
}
