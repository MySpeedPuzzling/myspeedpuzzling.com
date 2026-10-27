<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a puzzle's solves say about its difficulty in the candidate model (v4): its k candidate indices (drift-adjusted,
 * log) as sufficient statistics - the precision-weighted sums the posterior needs (Σ w, Σ w l) and the plain ones its
 * dispersion needs (Σ l, Σ l², Σ s² = the indices' own noise). As of a moment (the batch's present, a reconstruction's
 * solve); stored on puzzle_difficulty (candidate_* columns) by the insights batch.
 */
readonly final class CandidateEvidence
{
    public function __construct(
        public int $k = 0,
        public float $weightSum = 0.0,
        public float $weightedLogSum = 0.0,
        public float $logSum = 0.0,
        public float $logSquareSum = 0.0,
        public float $noiseSum = 0.0,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public function with(float $log, float $variance, float $weight): self
    {
        return new self(
            $this->k + 1,
            $this->weightSum + $weight,
            $this->weightedLogSum + $weight * $log,
            $this->logSum + $log,
            $this->logSquareSum + $log * $log,
            $this->noiseSum + $variance,
        );
    }

    /**
     * The posterior of the puzzle's log difficulty with a prior (mean, variance): [mean, variance]. Equal weights and
     * w = 1 ÷ s² make it today's shrunk mean with τ = s² ÷ v.
     *
     * @return array{0: float, 1: float}
     */
    public function posterior(float $priorLog, float $priorVariance): array
    {
        $priorPrecision = 1.0 / max($priorVariance, 1e-6);
        $precision = $this->weightSum + $priorPrecision;

        return [($this->weightedLogSum + $priorPrecision * $priorLog) / $precision, 1.0 / $precision];
    }

    /**
     * How much more the solvers disagree about the puzzle than their own noise explains (log variance, ≥ 0): the
     * plain variance of the indices less their mean noise, shrunk toward 0 with few indices ((k - 1) ÷ (k - 1 + shrink)).
     */
    public function dispersion(float $shrink): float
    {
        if ($this->k < 2) {
            return 0.0;
        }

        $variance = ($this->logSquareSum - $this->logSum ** 2 / $this->k) / ($this->k - 1);
        $excess = $variance - $this->noiseSum / $this->k;

        return max($excess, 0.0) * ($this->k - 1) / ($this->k - 1 + $shrink);
    }
}
