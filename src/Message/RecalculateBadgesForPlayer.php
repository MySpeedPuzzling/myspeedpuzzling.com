<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class RecalculateBadgesForPlayer
{
    public function __construct(
        public string $playerId,
        /**
         * True during the one-time launch/seed backfill: congratulation emails are
         * suppressed and achievement XP entries stay out of the weekly-delta leaderboard
         * (their earned_at is the backfill run time, which would otherwise flood the
         * launch week).
         */
        public bool $isBackfill = false,
    ) {
    }
}
