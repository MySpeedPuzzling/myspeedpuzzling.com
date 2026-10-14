<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Entity\StudioInvite;

/**
 * What EndStudioGuestHandler did (read it from the envelope's HandledStamp): the invitation as it is now, and whether
 * its guest was on air until now - then the controller takes their publishing away in the studio after the commit
 * (StudioLiveUpdates::ended()).
 */
readonly final class EndedStudioGuest
{
    public function __construct(
        public StudioInvite $invite,
        public bool $wasOnAir,
    ) {
    }
}
