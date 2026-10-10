<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;

/**
 * The time was marked as needing verification or the mark was cleared - by a moderator, the player's fix, or by SQL
 * that the suspicious time scan reconciled. A marked time counts nowhere, so XP and achievements follow it
 * (RecalculateProgressionOnSuspicionChange). Recorded next to PuzzleSolvingTimeModified, which the statistics follow.
 */
readonly final class PuzzleSolvingTimeSuspicionChanged
{
    public function __construct(
        public UuidInterface $puzzleSolvingTimeId,
    ) {
    }
}
