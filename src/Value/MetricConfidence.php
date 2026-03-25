<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum MetricConfidence: string
{
    case Insufficient = 'insufficient';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public static function fromSampleSize(int $sampleSize, int $minimum = 5): self
    {
        if ($sampleSize < $minimum) {
            return self::Insufficient;
        }

        if ($sampleSize < $minimum * 2) {
            return self::Low;
        }

        if ($sampleSize < $minimum * 4) {
            return self::Medium;
        }

        return self::High;
    }
}
