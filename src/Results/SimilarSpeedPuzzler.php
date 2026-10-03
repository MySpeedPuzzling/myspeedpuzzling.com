<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\SkillTier;

/**
 * "Someone at your speed" (FindSimilarSpeedPuzzler): a random player of similar skill, with the reasons the card shows
 * - similar speed (tier / top N %, or baseline time on the viewer's main piece count), puzzles in common, solves in the
 * last 30 days. Always a public profile, so the identity is not masked.
 */
readonly final class SimilarSpeedPuzzler
{
    public const string BASIS_SKILL = 'skill';
    public const string BASIS_BASELINE = 'baseline';

    public function __construct(
        public string $playerId,
        public null|string $playerName,
        public string $playerCode,
        public null|string $playerAvatar,
        public null|CountryCode $playerCountry,
        // What "similar speed" was measured on: skill percentile at 500 pieces, or the baseline time at $piecesCount
        public string $basis,
        public int $piecesCount,
        // Skill basis
        public null|SkillTier $viewerSkillTier,
        public null|float $viewerSkillPercentile,
        public null|SkillTier $skillTier,
        public null|float $skillPercentile,
        // Baseline basis
        public null|int $viewerBaselineSeconds,
        public null|int $baselineSeconds,
        // Distinct puzzles both solved solo
        public int $sharedPuzzles,
        // Their solo solves in the last 30 days
        public int $recentSolves,
    ) {
    }

    /**
     * "Top N %" for the skill basis (percentile 87.4 → 13).
     */
    public function topPercent(): null|int
    {
        if ($this->skillPercentile === null) {
            return null;
        }

        return max(1, (int) ceil(100 - $this->skillPercentile));
    }
}
