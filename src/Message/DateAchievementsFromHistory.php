<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Give the player's tiers still stamped by the launch backfill the moment their records first met them
 * (myspeedpuzzling:date-achievements-from-history, docs/features/badges.md "Real dates"). `dryRun` = count, change
 * nothing. Answers an AchievementDatingOutcome.
 */
readonly final class DateAchievementsFromHistory
{
    public function __construct(
        public string $playerId,
        public bool $dryRun = false,
    ) {
    }
}
