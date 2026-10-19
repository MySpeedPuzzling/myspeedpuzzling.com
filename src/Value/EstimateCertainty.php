<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How sure a personal time estimate is, read from its 80 % range relative to the time (docs/features/insights/
 * estimates.md): a measured 500 on a consistent player is ~25 % wide, a picture-only estimate ~60 %.
 * - `high`: the 80 % range at most 30 % of the time wide;
 * - `medium`: at most 55 %;
 * - `low`: wider.
 */
enum EstimateCertainty: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public const float HIGH_UP_TO = 0.30;
    public const float MEDIUM_UP_TO = 0.55;

    public static function fromRelativeWidth(float $relativeWidth): self
    {
        return match (true) {
            $relativeWidth <= self::HIGH_UP_TO => self::High,
            $relativeWidth <= self::MEDIUM_UP_TO => self::Medium,
            default => self::Low,
        };
    }

    public function translationKey(): string
    {
        return 'insights.estimate.certainty.' . $this->value;
    }
}
