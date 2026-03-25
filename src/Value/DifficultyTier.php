<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum DifficultyTier: int
{
    case VeryEasy = 1;
    case Easy = 2;
    case Moderate = 3;
    case Average = 4;
    case Challenging = 5;
    case Hard = 6;
    case Extreme = 7;

    public static function fromScore(float $score): self
    {
        return match (true) {
            $score < 0.70 => self::VeryEasy,
            $score < 0.85 => self::Easy,
            $score < 0.95 => self::Moderate,
            $score < 1.05 => self::Average,
            $score < 1.20 => self::Challenging,
            $score < 1.45 => self::Hard,
            default => self::Extreme,
        };
    }
}
