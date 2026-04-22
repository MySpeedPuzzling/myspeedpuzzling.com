<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum SkillTier: int
{
    case Enthusiast = 1;
    case Apprentice = 2;
    case Proficient = 3;
    case Advanced = 4;
    case Expert = 5;
    case Master = 6;
    case Legend = 7;

    public static function fromPercentile(float $percentile): self
    {
        return match (true) {
            $percentile >= 99.0 => self::Legend,
            $percentile >= 95.0 => self::Master,
            $percentile >= 85.0 => self::Expert,
            $percentile >= 70.0 => self::Advanced,
            $percentile >= 50.0 => self::Proficient,
            $percentile >= 25.0 => self::Apprentice,
            default => self::Enthusiast,
        };
    }

    public function minimumPercentile(): float
    {
        return match ($this) {
            self::Legend => 99.0,
            self::Master => 95.0,
            self::Expert => 85.0,
            self::Advanced => 70.0,
            self::Proficient => 50.0,
            self::Apprentice => 25.0,
            self::Enthusiast => 0.0,
        };
    }

    public function nextTier(): null|self
    {
        return match ($this) {
            self::Enthusiast => self::Apprentice,
            self::Apprentice => self::Proficient,
            self::Proficient => self::Advanced,
            self::Advanced => self::Expert,
            self::Expert => self::Master,
            self::Master => self::Legend,
            self::Legend => null,
        };
    }
}
