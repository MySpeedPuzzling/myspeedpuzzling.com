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
     * @param array<string, string> $reportedNameLanguages Puzzle id => the language the reporter gave its name
     * @param array<string, string> $mergedMeanwhile Reported puzzle id => the puzzle it was merged into since
     */
    public function __construct(
        public string $mergeRequestId,
        public string $submittedAt,
        public null|string $reporterName,
        public null|string $reporterCode,
        public null|string $sourcePuzzleId,
        public string $sourcePuzzleName,
        public array $candidates,
        // Reported puzzles that no longer exist and were not merged into any. A request left with fewer than two
        // puzzles can no longer be merged - it is closed as outdated (OutdatedPuzzleRequests)
        public array $missingPuzzleIds,
        public array $reportedNameLanguages = [],
        // A reported puzzle merged into another one since the report is that puzzle now - among the candidates
        public array $mergedMeanwhile = [],
    ) {
    }

    public function isActionable(): bool
    {
        return count($this->candidates) >= 2;
    }
}
