<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where an invitation to the studio of an online round stands (docs/features/online-competitions/README.md §5.12,
 * StudioInvite): sent and waiting for the participant's answer, on air, declined, ended by the host (or by the guest
 * leaving the studio), or left unanswered for StudioInvite::EXPIRES_AFTER_SECONDS - an expiry is checked on read
 * (StudioInvite::statusAt()), so a stored `invited` can already be expired.
 */
enum StudioInviteStatus: string
{
    case Invited = 'invited';
    case Joined = 'joined';
    case Declined = 'declined';
    case Ended = 'ended';
    case Expired = 'expired';
}
