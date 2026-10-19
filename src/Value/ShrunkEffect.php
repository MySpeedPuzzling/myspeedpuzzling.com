<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How far a group of ratios sits from 1.0 (1.0 = as expected), shown honestly: the median of the log ratios shrunk
 * toward "as expected" by n / (n + 8), with the distribution-free 95 % range of that median from its order statistics
 * (never a bootstrap - README §6, plan §4.3), shrunk the same way. Nothing is called better or worse on fewer than
 * MINIMUM_SAMPLE ratios, nor while the whole range is not beyond ±THRESHOLD.
 */
readonly final class ShrunkEffect
{
    public const int MINIMUM_SAMPLE = 5;
    public const float SHRINK = 8.0;
    public const float THRESHOLD = 0.015;

    public function __construct(
        public int $sampleSize,
        /** exp(shrunk median log ratio) − 1: −0.08 = 8 % faster than expected */
        public float $effect,
        public float $low,
        public float $high,
    ) {
    }

    /**
     * @param list<float> $ratios positive ratios, 1.0 = as expected
     */
    public static function of(array $ratios): null|self
    {
        $logs = [];

        foreach ($ratios as $ratio) {
            if ($ratio > 0.0) {
                $logs[] = log($ratio);
            }
        }

        $count = count($logs);

        if ($count < self::MINIMUM_SAMPLE) {
            return null;
        }

        sort($logs);

        $middle = intdiv($count, 2);
        $median = $count % 2 === 1 ? $logs[$middle] : ($logs[$middle - 1] + $logs[$middle]) / 2.0;

        // 1-based ranks of the order statistics bounding the median at 95 %
        $spread = 1.96 * sqrt($count);
        $lowRank = max(1, (int) floor(($count - $spread) / 2.0));
        $highRank = min($count, (int) ceil(1.0 + ($count + $spread) / 2.0));

        $shrink = $count / ($count + self::SHRINK);

        return new self(
            sampleSize: $count,
            effect: exp($median * $shrink) - 1.0,
            low: exp($logs[$lowRank - 1] * $shrink) - 1.0,
            high: exp($logs[$highRank - 1] * $shrink) - 1.0,
        );
    }

    /** Faster than expected, and the whole range says so */
    public function isFaster(): bool
    {
        return $this->high < -self::THRESHOLD;
    }

    /** Slower than expected, and the whole range says so */
    public function isSlower(): bool
    {
        return $this->low > self::THRESHOLD;
    }
}
