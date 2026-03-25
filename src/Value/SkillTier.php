<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum SkillTier: int
{
    case Casual = 1;
    case Enthusiast = 2;
    case Proficient = 3;
    case Advanced = 4;
    case Expert = 5;
    case Master = 6;
    case Grandmaster = 7;

    public static function fromPercentile(float $percentile): self
    {
        return match (true) {
            $percentile >= 99.0 => self::Grandmaster,
            $percentile >= 95.0 => self::Master,
            $percentile >= 85.0 => self::Expert,
            $percentile >= 70.0 => self::Advanced,
            $percentile >= 50.0 => self::Proficient,
            $percentile >= 25.0 => self::Enthusiast,
            default => self::Casual,
        };
    }
}
