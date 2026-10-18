<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use DateTimeImmutable;

readonly final class ResultsMilestone
{
    public function __construct(
        public int $results,
        public int $nextMilestone,
        /** Results a month over the last 6 months */
        public float $perMonth,
        /** null = no results lately */
        public null|DateTimeImmutable $expectedAt,
    ) {
    }
}
