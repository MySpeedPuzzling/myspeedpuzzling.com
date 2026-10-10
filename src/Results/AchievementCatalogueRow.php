<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

/**
 * One row of the achievements catalogue. The personal parts (earned tier, progress) are filled for members only -
 * a non-member never learns which achievements they hold (docs/features/xp-levels/launch-2026-10.md).
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
}
