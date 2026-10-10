<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\SkillTier;

/**
 * MSP Rating place (500 pieces, the ladder's piece count) and skill tier movement over a digest period, from the daily
 * `player_rating_snapshot` rows. The place shown is today's place on the ladder; the movement compares the snapshot
 * before the period with the last one inside it (both in the snapshot's own pool, so the arrow is consistent).
 */
readonly final class PeriodRatingChange
{
    /**
     * @param array<int, array{before: null|SkillTier, after: SkillTier}> $skillTierChanges pieces count => tiers, only
     *     where the tier changed (members only)
     */
    public function __construct(
        public null|int $rank,
        public null|int $rankTotal,
        /** Places gained (positive) or lost (negative); 0 = unchanged or unknown */
        public int $placesGained,
        public array $skillTierChanges = [],
    ) {
    }

    public function hasNews(): bool
    {
        return $this->placesGained !== 0 || $this->skillTierChanges !== [];
    }
}
