<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ImagePriorInput;
use SpeedPuzzling\Web\Value\PictureFactors;
use SpeedPuzzling\Web\Value\PredictionModelParameters;

/**
 * What one evaluation run (PredictionEvaluator) fitted and loaded before its cut-off - shared by the shown model's and
 * the candidate's measurements, so every candidate variant is measured on the very same rows.
 */
final class EvaluationContext
{
    private null|CandidateEngine $candidateEngine = null;

    /**
     * @param array<string, ImagePriorInput> $imageEstimates
     */
    public function __construct(
        public readonly FirstAttemptSet $set,
        public readonly PredictionEngine $engine,
        public readonly PriorLookup $priors,
        public readonly RepeatHistory $repeats,
        public readonly PredictionModelParameters $parameters,
        public readonly array $imageEstimates,
        public readonly float $from,
        public readonly float $cut,
        public readonly DateTimeImmutable $fitBefore,
        public readonly AttemptFlags $flags,
        public readonly UnboxedFirstTries $unboxed,
        /** @var array<string, PictureFactors> by puzzle id - the candidate's taste factor */
        public readonly array $pictures = [],
    ) {
    }

    public function candidateEngine(): CandidateEngine
    {
        return $this->candidateEngine ??= new CandidateEngine($this->engine, $this->flags);
    }
}
