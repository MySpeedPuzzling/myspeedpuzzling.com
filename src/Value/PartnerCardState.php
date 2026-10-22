<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which partner card the event page shows a signed-in viewer (docs/features/partner-finder.md "Part B"):
 * - Invite: going (or, with managed registration, whatever the registration says), not listed, not in a pair/team
 *   entry, not closed - "Going without a partner yet?"; highlighted right after "I'm going" (EventJustJoined)
 * - Listed: the viewer's own open listing - a one-line status, never closable
 * - Browse: not going (plain registration), listings exist, not closed - "N puzzlers are looking for a partner here"
 */
enum PartnerCardState: string
{
    case Invite = 'invite';
    case Listed = 'listed';
    case Browse = 'browse';
}
