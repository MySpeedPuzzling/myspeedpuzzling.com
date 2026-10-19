<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use SpeedPuzzling\Web\Value\TimePredictionMethod;

/**
 * One dot of expected vs actual: deviation = actual ÷ expected − 1 (negative = faster than expected).
 */
readonly final class InsightsExpectedPoint
{
    public function __construct(
        public string $timeId,
        public int $expectedSeconds,
        public int $actualSeconds,
        public float $deviation,
        public null|TimePredictionMethod $method,
    ) {
    }
}
