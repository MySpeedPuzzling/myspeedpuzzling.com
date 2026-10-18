<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

readonly final class ImprovementCurveResult
{
    /**
     * @param list<ImprovementCurveSize> $sizes most first tries first
     */
    public function __construct(
        public array $sizes,
    ) {
    }
}
