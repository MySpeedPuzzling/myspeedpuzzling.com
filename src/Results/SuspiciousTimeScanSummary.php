<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What one run of DetectSuspiciousTimes did (or, as a dry run, would do).
 */
readonly final class SuspiciousTimeScanSummary
{
    /**
     * @param array<string, int> $raisedByDirection fast / slow
     * @param array<string, int> $raisedByTier strong / possible
     * @param array<string, int> $raisedBySource prediction / baseline / pace / none (no expected time: new players, pair/team results)
     * @param list<SuspiciousTimeRaisedRow> $raisedRows only for a dry run
     */
    public function __construct(
        public bool $dryRun,
        public int $references,
        public int $checked,
        public int $clear,
        public int $noData,
        public int $raised,
        public array $raisedByDirection,
        public array $raisedByTier,
        public array $raisedBySource,
        public int $newCases,
        public int $refreshedCases,
        public int $reopenedCases,
        public int $goneCases,
        public int $markedOutsideApp,
        public int $unmarkedOutsideApp,
        // Marks whose time became another entry outside the edit form: unmarked automatically / back to the moderators
        public int $changedMarksUnmarked = 0,
        public int $changedMarksToModerators = 0,
        public array $raisedRows = [],
    ) {
    }
}
