<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\DuplicateKind;
use SpeedPuzzling\Web\Value\DuplicateTier;

/**
 * An open duplicate case of the viewer with both copies (docs/features/duplicate-results.md, "Review page").
 */
readonly final class DuplicateReviewCase
{
    public function __construct(
        public string $caseId,
        public DuplicateTier $tier,
        public DuplicateKind $kind,
        public FirstTryPuzzle $puzzle,
        // Saved first
        public DuplicateReviewCopy $older,
        public DuplicateReviewCopy $newer,
    ) {
    }

    /**
     * Tier A/B: "most likely saved twice", the older copy preselected. Tier C is asked neutrally.
     */
    public function isLikely(): bool
    {
        return $this->tier !== DuplicateTier::Possible;
    }

    public function sameTime(): bool
    {
        return $this->older->secondsToSolve === $this->newer->secondsToSolve;
    }

    public function involves(string $timeId): bool
    {
        return $this->older->timeId === strtolower($timeId) || $this->newer->timeId === strtolower($timeId);
    }

    /**
     * @return list<DuplicateReviewCopy>
     */
    public function copies(): array
    {
        return [$this->older, $this->newer];
    }
}
