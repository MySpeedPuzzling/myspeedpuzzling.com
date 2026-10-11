<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

/**
 * One cell of the achievements shelf (docs/features/xp-levels/ux-polish/design-spec.md §1). The personal parts
 * (earned tier, progress, tiers held) are filled for members only - a non-member never learns which achievements
 * they hold (docs/features/xp-levels/launch-2026-10.md).
 */
readonly final class AchievementCatalogueRow
{
    public function __construct(
        public BadgeType $type,
        /** The tier whose picture stands for the row: the highest earned, else Bronze */
        public BadgeTier $displayTier,
        public null|BadgeTier $earnedTier,
        public null|BadgeProgress $progress,
        /** Share of puzzlers holding the row's tier that matters: the next one, else the highest earned, else Bronze */
        public float $rarityPercent,
        /** The owner's highest tier waits for its reveal: a "?" seal leading to the reveal page */
        public bool $unrevealed = false,
        /** How many of the five tiers are held (the summary's "41 of 80 tiers") */
        public int $tiersEarned = 0,
        /** The highest tier was earned in the last 7 days - the owner's "New" dot */
        public bool $newlyEarned = false,
        /** Share of puzzlers holding the highest earned tier (the "rarer first" order among equal tiers) */
        public float $earnedRarityPercent = 100.0,
        /** When the highest tier was earned */
        public null|DateTimeImmutable $earnedAt = null,
        /** The highest tier was earned by the launch backfill: no date, no "New" (design-spec §8.1) */
        public bool $heldAtLaunch = false,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->earnedTier === BadgeTier::Diamond;
    }

    /** The tier the row talks about: the one to reach next, else the one held */
    public function focusTier(): BadgeTier
    {
        return $this->progress !== null ? $this->progress->nextTier : ($this->earnedTier ?? BadgeTier::Bronze);
    }

    /** Tiers 4 and 5 shine once when the shelf loads (design-spec §1 "The cell") */
    public function shines(): bool
    {
        return $this->unrevealed === false && $this->earnedTier !== null && $this->earnedTier->value >= BadgeTier::Platinum->value;
    }
}
