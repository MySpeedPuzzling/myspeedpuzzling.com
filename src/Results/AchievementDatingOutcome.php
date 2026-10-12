<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What DateAchievementsFromHistory did for one player: tiers given their real date, tiers left with the launch stamp
 * (no date found, or none earlier than the stamp), and whether the replay of the history matched the player's current
 * records at all (when it does not, nothing of theirs is dated).
 */
readonly final class AchievementDatingOutcome
{
    public function __construct(
        public int $dated = 0,
        public int $undated = 0,
        public bool $replayMatched = true,
    ) {
    }
}
