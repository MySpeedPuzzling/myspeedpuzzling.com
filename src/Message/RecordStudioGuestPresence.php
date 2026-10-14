<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;

/**
 * LiveKit saw a participant's camera device join or leave the studio of a round (its webhook, `POST /-/livekit-webhook`,
 * contract phase-1b.md §5): for their invitation that is on air (`joined`), joining sets `onAirFrom` the first time,
 * leaving ends it (`ended` + `onAirTo`). Anything else - no such invitation, a round or a participant we do not know -
 * changes nothing; a webhook sent twice changes nothing more.
 */
readonly final class RecordStudioGuestPresence
{
    public function __construct(
        public string $roundId,
        public string $participantId,
        // true: joined the studio, false: left it
        public bool $present,
        // When it happened (the webhook's own time)
        public DateTimeImmutable $at,
    ) {
    }
}
