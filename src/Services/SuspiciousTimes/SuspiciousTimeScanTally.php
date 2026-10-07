<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SuspiciousTimes;

use SpeedPuzzling\Web\Results\SuspiciousTimeRaisedRow;
use SpeedPuzzling\Web\Results\SuspiciousTimeScanSummary;
use SpeedPuzzling\Web\Value\SuspicionAssessment;
use SpeedPuzzling\Web\Value\SuspicionCheckOutcome;

/**
 * The counters of one scan run - created per run by the handler, never shared.
 */
final class SuspiciousTimeScanTally
{
    public int $references = 0;
    public int $checked = 0;
    public int $clear = 0;
    public int $noData = 0;
    public int $raised = 0;
    /** @var array<string, int> */
    public array $raisedByDirection = [];
    /** @var array<string, int> */
    public array $raisedByTier = [];
    /** @var array<string, int> */
    public array $raisedBySource = [];
    public int $newCases = 0;
    public int $refreshedCases = 0;
    public int $reopenedCases = 0;
    public int $goneCases = 0;
    public int $markedOutsideApp = 0;
    public int $unmarkedOutsideApp = 0;
    // Marks whose time became another entry outside the edit form, judged again
    public int $changedMarksUnmarked = 0;
    public int $changedMarksToModerators = 0;
    /** @var list<SuspiciousTimeRaisedRow> */
    public array $raisedRows = [];

    public function __construct(
        public readonly bool $dryRun,
    ) {
    }

    public function checked(SuspicionAssessment $assessment): void
    {
        $this->checked++;

        if ($assessment->outcome === SuspicionCheckOutcome::Raised) {
            $this->raised++;
        } elseif ($assessment->outcome === SuspicionCheckOutcome::NoData) {
            $this->noData++;
        } else {
            $this->clear++;
        }
    }

    /**
     * A raised time with its explanations - what its tier is.
     */
    public function raisedWithEvidence(SuspicionAssessment $assessment): void
    {
        $direction = $assessment->direction()->value ?? 'unknown';
        $tier = $assessment->tier->value ?? 'unknown';
        $source = $assessment->expectedSource->value ?? 'none';

        $this->raisedByDirection[$direction] = ($this->raisedByDirection[$direction] ?? 0) + 1;
        $this->raisedByTier[$tier] = ($this->raisedByTier[$tier] ?? 0) + 1;
        $this->raisedBySource[$source] = ($this->raisedBySource[$source] ?? 0) + 1;
    }

    public function summary(): SuspiciousTimeScanSummary
    {
        ksort($this->raisedByDirection);
        ksort($this->raisedByTier);
        ksort($this->raisedBySource);

        return new SuspiciousTimeScanSummary(
            dryRun: $this->dryRun,
            references: $this->references,
            checked: $this->checked,
            clear: $this->clear,
            noData: $this->noData,
            raised: $this->raised,
            raisedByDirection: $this->raisedByDirection,
            raisedByTier: $this->raisedByTier,
            raisedBySource: $this->raisedBySource,
            newCases: $this->newCases,
            refreshedCases: $this->refreshedCases,
            reopenedCases: $this->reopenedCases,
            goneCases: $this->goneCases,
            markedOutsideApp: $this->markedOutsideApp,
            unmarkedOutsideApp: $this->unmarkedOutsideApp,
            changedMarksUnmarked: $this->changedMarksUnmarked,
            changedMarksToModerators: $this->changedMarksToModerators,
            raisedRows: $this->raisedRows,
        );
    }
}
