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
        public null|int $predictedSeconds,
        public null|int $rangeLowSeconds,
        public null|int $rangeHighSeconds,
        public bool $isPersonalized,
        public null|int $personalSolveCount,
        public null|int $predictedAttemptNumber,
        public null|int $lastTimeSeconds,
    ) {
    }

    public static function fromResult(null|TimePredictionResult $prediction): self
    {
        return new self(
            predictedSeconds: $prediction?->predictedSeconds,
            rangeLowSeconds: $prediction?->rangeLowSeconds,
            rangeHighSeconds: $prediction?->rangeHighSeconds,
            isPersonalized: $prediction !== null && $prediction->isPersonalized,
            personalSolveCount: $prediction?->personalSolveCount,
            predictedAttemptNumber: $prediction?->predictedAttemptNumber,
            lastTimeSeconds: $prediction?->lastTimeSeconds,
        );
    }
}
