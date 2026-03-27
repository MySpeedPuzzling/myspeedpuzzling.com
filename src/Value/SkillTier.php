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

    public function minimumPercentile(): float
    {
        return match ($this) {
            self::Grandmaster => 99.0,
            self::Master => 95.0,
            self::Expert => 85.0,
            self::Advanced => 70.0,
            self::Proficient => 50.0,
            self::Enthusiast => 25.0,
            self::Casual => 0.0,
        };
    }

    public function nextTier(): null|self
    {
        return match ($this) {
            self::Casual => self::Enthusiast,
            self::Enthusiast => self::Proficient,
            self::Proficient => self::Advanced,
            self::Advanced => self::Expert,
            self::Expert => self::Master,
            self::Master => self::Grandmaster,
            self::Grandmaster => null,
        };
    }
}
