<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;

/**
 * A time the scan checks (Query\GetSuspiciousTimeCandidates) with everything the classifier needs except the
 * player's pace and the evidence. Prediction, baseline and difficulty are null for pair/team results.
 */
readonly final class SuspicionCandidate
{
    public function __construct(
        public string $timeId,
        public string $playerId,
        public string $puzzleId,
        public int $piecesCount,
        public int $seconds,
        public PuzzlingType $puzzlingType,
        // COALESCE(finished_at, tracked_at) as a Unix timestamp (the naive timestamp read as UTC, like Postgres does)
        public int $solvedAt,
        public null|int $predictedSeconds,
        // A personal prediction: the time of the attempt it was built on
        public null|int $previousAttemptSeconds,
        // Only looked up when the time is far faster than a personal prediction: an earlier attempt of the puzzle has
        // a pending or marked slow case
        public bool $previousAttemptRaisedSlow,
        public null|int $baselineSeconds,
        public null|float $difficultyScore,
        public string $fingerprint,
        public null|string $caseId,
        public null|SuspiciousTimeCaseStatus $caseStatus,
        // A moderator's slow threshold for the puzzle at its current piece count - pair/team results too
        public null|float $slowThreshold = null,
    ) {
    }
}
