<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How rare an achievement tier is, in words, from the share of puzzlers holding it - the bands PlayStation uses
 * (docs/features/xp-levels/ux-polish/design-spec.md §2, "Facts line"): Common ≥ 50 %, Rare ≥ 15 %, Very rare ≥ 5 %,
 * Ultra rare below. The percentage stays next to the word; the word only makes it quick to read.
 */
enum AchievementRarityBand: string
{
    case Common = 'common';
    case Rare = 'rare';
    case VeryRare = 'very_rare';
    case UltraRare = 'ultra_rare';

    public static function fromPercent(float $percent): self
    {
        return match (true) {
            $percent >= 50.0 => self::Common,
            $percent >= 15.0 => self::Rare,
            $percent >= 5.0 => self::VeryRare,
            default => self::UltraRare,
        };
    }

    public function translationKey(): string
    {
        return 'badges.medal.rarity.' . $this->value;
    }
}
