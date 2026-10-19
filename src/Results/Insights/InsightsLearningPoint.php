<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * Attempt N of a learning curve: your (or everybody's) time on attempt N ÷ attempt 1 (1.0 for attempt 1); null when
 * it rests on too little.
 */
readonly final class InsightsLearningPoint
{
    public function __construct(
        public int $attempt,
        public null|float $factor,
        public int $sampleSize,
    ) {
    }
}
