<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

readonly final class PlannedResultReviewContact
{
    /**
     * @param list<string> $caseIds
     * @param list<string> $removalIds
     */
    public function __construct(
        public ResultReviewContactType $type,
        public int $priority,
        public array $caseIds,
        public array $removalIds,
    ) {
    }
}
