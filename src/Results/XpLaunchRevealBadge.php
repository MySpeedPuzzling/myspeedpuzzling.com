<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\AchievementRarityBand;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use SpeedPuzzling\Web\Value\XpLaunchRevealHighlight;

/**
 * One achievement of the XP launch reveal: its highest earned tier (null = a single-tier achievement) and the
 * Achievement Points all of its earned tiers brought - every tier counts once (Gold = Bronze 5 + Silver 10 + Gold 25).
 * The deck's cards also carry the facts the achievements pages show (GetXpLaunchRevealBadges::withFacts()): the
 * progress to the next tier, how many puzzlers hold the tier and what is special about it.
 */
readonly final class XpLaunchRevealBadge
{
    public function __construct(
        public BadgeType $type,
        public null|BadgeTier $tier,
        public int $points,
        public string $badgeId,
        public bool $revealed,
        /** Toward the next tier - null for Diamond, a single-tier achievement, or without the facts */
        public null|BadgeProgress $progress = null,
        /** Puzzlers holding this tier (AchievementRarityProvider, the single tier under 0) */
        public int $holders = 0,
        /** Their share of all puzzlers, 0-100 */
        public float $sharePercent = 0.0,
        public null|XpLaunchRevealHighlight $highlight = null,
    ) {
    }

    /**
     * What the highest tier asked for ("Solve 500 distinct puzzles"); a single-tier achievement has no ladder, its
     * description says why it was given.
     */
    public function requirementTranslationKey(): string
    {
        if ($this->tier === null) {
            return 'badges.description.' . $this->type->value;
        }

        return 'badges.requirement.' . $this->type->value . '_' . $this->tier->value;
    }

    public function nextRequirementTranslationKey(): null|string
    {
        if ($this->progress === null) {
            return null;
        }

        return 'badges.requirement.' . $this->type->value . '_' . $this->progress->nextTier->value;
    }

    public function isTopTier(): bool
    {
        return $this->tier === BadgeTier::Diamond;
    }

    /** Rarity in words for the chip; a common tier gets no chip (design-spec §8.3) */
    public function rarityBand(): AchievementRarityBand
    {
        return AchievementRarityBand::fromPercent($this->sharePercent);
    }
}
