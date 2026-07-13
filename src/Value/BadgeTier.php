<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum BadgeTier: int
{
    case Bronze = 1;
    case Silver = 2;
    case Gold = 3;
    case Platinum = 4;
    case Diamond = 5;

    /**
     * Achievement Points AND achievement XP granted by a single-tier achievement
     * (e.g. Early Adopter) — tiered achievements use points().
     */
    public const int SINGLE_TIER_POINTS = 25;

    /**
     * Locked values (§1.6): each earned tier grants this many Achievement Points and
     * the same amount of XP, once, forever.
     */
    public function points(): int
    {
        return match ($this) {
            self::Bronze => 5,
            self::Silver => 10,
            self::Gold => 25,
            self::Platinum => 50,
            self::Diamond => 100,
        };
    }

    public function romanNumeral(): string
    {
        return match ($this) {
            self::Bronze => 'I',
            self::Silver => 'II',
            self::Gold => 'III',
            self::Platinum => 'IV',
            self::Diamond => 'V',
        };
    }

    public function cssClass(): string
    {
        return 'badge-tier-' . strtolower($this->name);
    }

    public function translationKey(): string
    {
        return 'badges.tier.' . strtolower($this->name);
    }
}
