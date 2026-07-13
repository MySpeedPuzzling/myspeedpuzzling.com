<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Everything the post-solve receipt needs to label its lines for one participant:
 * occurrence position (repeat discounts fold into the base line's LABEL, never a
 * negative line) and which bonuses are still pending settlement.
 */
readonly final class SolveXpDisplayInfo
{
    public function __construct(
        public int $occurrenceIndex,
        public bool $isTimed,
        public bool $isSolo,
        public bool $isBackfill,
        public bool $puzzleHasDifficultyTier,
        public bool $speedMedianReliable,
    ) {
    }
}
