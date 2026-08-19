<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use SpeedPuzzling\Web\Results\PuzzleDifficultyResult;
use SpeedPuzzling\Web\Value\MetricConfidence;

/**
 * Puzzle difficulty (Puzzle Insights) - members-only, exactly as on the website.
 * An endpoint returns null for this object when the token owner is not a member
 * (or the token is a machine token); when it is present, the object is always
 * complete and carries null *inside* for "not enough data yet".
 */
final class PuzzleDifficultyResponse
{
    public function __construct(
        public null|float $score,
        public null|string $level,
        public string $confidence,
        public int $sampleSize,
    ) {
    }

    public static function fromResult(PuzzleDifficultyResult $difficulty): self
    {
        return new self(
            score: $difficulty->difficultyScore,
            level: $difficulty->difficultyTier?->toApiValue(),
            confidence: $difficulty->confidence->value,
            sampleSize: $difficulty->sampleSize,
        );
    }

    /**
     * A member looking at a puzzle that has no difficulty row yet (the
     * recalculation is batch): "insufficient data", not "members only".
     */
    public static function insufficient(): self
    {
        return new self(
            score: null,
            level: null,
            confidence: MetricConfidence::Insufficient->value,
            sampleSize: 0,
        );
    }
}
