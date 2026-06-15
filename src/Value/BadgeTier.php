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
