<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One page of a tab of the time verification queue: the cases in their order, and how many the list has in all.
 */
readonly final class SuspiciousTimeQueuePage
{
    /**
     * @param list<string> $caseIds
     */
    public function __construct(
        public array $caseIds,
        public int $total,
    ) {
    }
}
