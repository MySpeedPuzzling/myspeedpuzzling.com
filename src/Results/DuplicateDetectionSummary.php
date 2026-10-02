<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class DuplicateDetectionSummary
{
    /**
     * @param list<string> $certainCaseIds the open Tier A cases, for the automatic removal (DailyDuplicateDetection)
     */
    public function __construct(
        public int $candidates,
        public int $newCases,
        public int $goneCases,
        public array $certainCaseIds = [],
        public int $autoRemoved = 0,
        public int $autoRemovalsFailed = 0,
    ) {
    }

    public function withAutoRemovals(int $removed, int $failed): self
    {
        return new self($this->candidates, $this->newCases, $this->goneCases, $this->certainCaseIds, $removed, $failed);
    }
}
