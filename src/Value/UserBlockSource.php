<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Who put a block in place - see docs/features/player-blocklist.md.
 */
enum UserBlockSource: string
{
    /** The blocker chose it: listed in their settings, theirs to remove. */
    case Self = 'self';

    /**
     * Imposed on the blocker by an admin, to protect the blocked player. The blocker
     * is never shown it and cannot remove it.
     */
    case Admin = 'admin';
}
