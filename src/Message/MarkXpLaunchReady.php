<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Flip the XP launch switch - only if the history verifies (MarkXpLaunchReadyHandler). Sent by
 * `myspeedpuzzling:xp-backfill` at the end of a complete run, or with --verify-only.
 */
readonly final class MarkXpLaunchReady
{
}
