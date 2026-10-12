<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;

/**
 * Give the player's tiers still stamped by the launch backfill the moment their records first met them
 * (myspeedpuzzling:date-achievements-from-history, docs/features/badges.md "Real dates"). `dryRun` = count, change
 * nothing. `earnedSince` = the tiers earned from that moment on instead of the launch backfill's (a recount after a
 * corrected rule granted them today). Answers an AchievementDatingOutcome.
 */
readonly final class DateAchievementsFromHistory
{
    public function __construct(
        public string $playerId,
        public bool $dryRun = false,
        public null|DateTimeImmutable $earnedSince = null,
    ) {
    }
}
