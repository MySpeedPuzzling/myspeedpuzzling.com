<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A pending merge request together with every puzzle it proposes to merge.
 */
readonly final class PuzzleMergeReviewItem
{
    /**
     * @param array<PuzzleMergeReviewCandidate> $candidates
     * @param array<string> $missingPuzzleIds
     */
    public function __construct(
        public string $mergeRequestId,
        public string $submittedAt,
        public null|string $reporterName,
        public null|string $reporterCode,
        public null|string $sourcePuzzleId,
        public string $sourcePuzzleName,
        public array $candidates,
        // Reported puzzles that no longer exist - deleted by an earlier merge. A
        // request left with fewer than two live puzzles can no longer be merged.
        public array $missingPuzzleIds,
    ) {
    }

    public function isActionable(): bool
    {
        return count($this->candidates) >= 2;
    }
}
