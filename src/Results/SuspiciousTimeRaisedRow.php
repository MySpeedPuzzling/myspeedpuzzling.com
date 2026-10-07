<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SuspicionAssessment;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;

/**
 * One time a dry run would raise - a line of the --report CSV.
 */
readonly final class SuspiciousTimeRaisedRow
{
    public function __construct(
        public string $timeId,
        public string $playerId,
        public string $puzzleId,
        public int $piecesCount,
        public int $seconds,
        public PuzzlingType $puzzlingType,
        public int $solvedAt,
        public SuspicionAssessment $assessment,
        // The case the time has today, if any
        public null|SuspiciousTimeCaseStatus $caseStatus,
    ) {
    }
}
