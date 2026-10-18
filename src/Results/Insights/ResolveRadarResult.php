<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

readonly final class ResolveRadarResult
{
    /**
     * @param list<ResolveCandidate> $candidates biggest likely gain first
     */
    public function __construct(
        public array $candidates,
        public int $onShelfCount,
    ) {
    }
}
