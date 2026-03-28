<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum DifficultyTier: int
{
    case VeryEasy = 1;
    case Easy = 2;
    case Average = 3;
    case Challenging = 4;
    case Hard = 5;
    case VeryHard = 6;

    public static function fromScore(float $score): self
    {
        return match (true) {
            $score < 0.75 => self::VeryEasy,
            $score < 0.90 => self::Easy,
            $score < 1.10 => self::Average,
            $score < 1.25 => self::Challenging,
            $score < 1.45 => self::Hard,
            default => self::VeryHard,
        };
    }
}
