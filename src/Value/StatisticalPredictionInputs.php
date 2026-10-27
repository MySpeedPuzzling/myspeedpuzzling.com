<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\PriorContext;

/**
 * Everything a statistical (first-try) prediction of one player on one puzzle rests on, as the live tables hold it
 * now (GetPlayerPrediction, GetPlayerPredictions) or as it was right before a past solve (PredictionReconstructor) -
 * TimePredictionCalculator::statistical() turns it into the prediction, the same way for all of them.
 */
readonly final class StatisticalPredictionInputs
{
    public function __construct(
        public int $piecesCount,
        // The player's baseline at the piece count: direct (5+ qualifying first attempts) or interpolated/extrapolated
        public null|int $ownBaselineSeconds,
        public bool $ownBaselineDirect,
        // Qualifying first attempts behind a direct own baseline (0 otherwise)
        public int $ownQualifyingCount,
        // Drift and spread (log) of the pair's pace-history row before the prediction; null = none (0 and the global spread)
        public null|float $ownDrift,
        public null|float $ownSpread,
        // The player's direct 500-piece pace, for the thin-size rule
        public null|int $pace500Seconds,
        public null|float $drift500,
        public null|float $spread500,
        // The puzzle: index count k, sum of the log indices, the median score when rated (k >= 5)
        public int $kIndices,
        public null|float $sumLogIndices,
        public null|float $difficultyScore,
        public PriorContext $prior,
        // The candidate model (v4) only - never read by the shown one: the puzzle's precision-weighted evidence and
        // its puzzle-weighted brand × pieces prior (PuzzleWeightedBrandPrior); null = not loaded
        public null|CandidateEvidence $candidateEvidence = null,
        public null|float $candidateBrandPriorLog = null,
        // The player's qualifying first attempts (every size) before the prediction - the candidate's experience offset
        public null|int $candidateFirstTries = null,
    ) {
    }

    /**
     * The same inputs with the candidate model's puzzle evidence and brand prior.
     */
    public function withCandidate(null|CandidateEvidence $evidence, null|float $brandPriorLog, null|int $firstTries = null): self
    {
        return new self(
            piecesCount: $this->piecesCount,
            ownBaselineSeconds: $this->ownBaselineSeconds,
            ownBaselineDirect: $this->ownBaselineDirect,
            ownQualifyingCount: $this->ownQualifyingCount,
            ownDrift: $this->ownDrift,
            ownSpread: $this->ownSpread,
            pace500Seconds: $this->pace500Seconds,
            drift500: $this->drift500,
            spread500: $this->spread500,
            kIndices: $this->kIndices,
            sumLogIndices: $this->sumLogIndices,
            difficultyScore: $this->difficultyScore,
            prior: $this->prior,
            candidateEvidence: $evidence,
            candidateBrandPriorLog: $brandPriorLog,
            candidateFirstTries: $firstTries,
        );
    }

    /**
     * The same inputs with the puzzle's image estimate in the prior context - the side-by-side `blend` predictor's
     * (and, without the solves, the `image` one's); null = the statistical predictor's.
     */
    public function withImageEstimate(null|ImagePriorInput $imageEstimate): self
    {
        return $this->with(prior: $this->prior->withImageEstimate($imageEstimate));
    }

    /**
     * The same inputs as if nobody had solved the puzzle: the `image` predictor rests on the picture and the pieces
     * alone (Insights plan §5.4), whatever the solves say.
     */
    public function withoutSolves(): self
    {
        return $this->with(kIndices: 0, sumLogIndices: null, difficultyScore: null);
    }

    private function with(
        null|int $kIndices = null,
        null|float $sumLogIndices = null,
        null|float $difficultyScore = null,
        null|PriorContext $prior = null,
    ): self {
        $solvesChanged = $kIndices !== null;

        return new self(
            piecesCount: $this->piecesCount,
            ownBaselineSeconds: $this->ownBaselineSeconds,
            ownBaselineDirect: $this->ownBaselineDirect,
            ownQualifyingCount: $this->ownQualifyingCount,
            ownDrift: $this->ownDrift,
            ownSpread: $this->ownSpread,
            pace500Seconds: $this->pace500Seconds,
            drift500: $this->drift500,
            spread500: $this->spread500,
            kIndices: $kIndices ?? $this->kIndices,
            sumLogIndices: $solvesChanged ? $sumLogIndices : $this->sumLogIndices,
            difficultyScore: $solvesChanged ? $difficultyScore : $this->difficultyScore,
            prior: $prior ?? $this->prior,
            candidateEvidence: $solvesChanged ? null : $this->candidateEvidence,
            candidateBrandPriorLog: $this->candidateBrandPriorLog,
            candidateFirstTries: $this->candidateFirstTries,
        );
    }
}
