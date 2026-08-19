<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use SpeedPuzzling\Web\Results\TimePredictionResult;

/**
 * The token owner's own predicted time for a puzzle (Puzzle Insights) -
 * members-only and self-only, exactly as on the website. An endpoint returns
 * null for this object when the owner is not entitled (not a member, opted out
 * of time predictions, machine token, no results:read); when present, every
 * field is null and is_personalized false if there is nothing to predict from.
 */
final class TimePredictionResponse
{
    public function __construct(
        public null|int $predicted_seconds,
        public null|int $range_low_seconds,
        public null|int $range_high_seconds,
        public bool $is_personalized,
        public null|int $personal_solve_count,
        public null|int $predicted_attempt_number,
        public null|int $last_time_seconds,
    ) {
    }

    public static function fromResult(null|TimePredictionResult $prediction): self
    {
        return new self(
            predicted_seconds: $prediction?->predictedSeconds,
            range_low_seconds: $prediction?->rangeLowSeconds,
            range_high_seconds: $prediction?->rangeHighSeconds,
            is_personalized: $prediction !== null && $prediction->isPersonalized,
            personal_solve_count: $prediction?->personalSolveCount,
            predicted_attempt_number: $prediction?->predictedAttemptNumber,
            last_time_seconds: $prediction?->lastTimeSeconds,
        );
    }
}
