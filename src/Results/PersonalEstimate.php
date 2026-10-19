<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\EstimateBasis;
use SpeedPuzzling\Web\Value\EstimateCertainty;
use SpeedPuzzling\Web\Value\EstimateTime;
use SpeedPuzzling\Web\Value\PredictionWindow;

/**
 * One puzzle's personal time estimate as the puzzle page card, the list line and the sheet show it
 * (docs/features/insights/estimates.md). The time is the prediction every other surface shows for the same player and
 * puzzle - personal or statistical (GetPlayerPrediction) - and, only for a puzzle nobody has solved (k = 0) where
 * ImageEstimateGate passes, the picture-only one. A player with no pace at any piece count gets the community's usual
 * time for the piece count instead (`community`: no prediction, no window, no bar).
 *
 * The tier is the puzzle's, never personal: measured from 5 indices on, else estimated (dashed, "?") - from the shrunk
 * difficulty at 1-4 indices, from the picture at 0 where the gate passes; null = none to show.
 */
readonly final class PersonalEstimate
{
    /** The certainty bar spans the time × 0.5 … × 1.5; the 80 % range is drawn on it */
    private const float BAR_FROM = 0.5;
    private const float BAR_TO = 1.5;

    public function __construct(
        public string $puzzleId,
        public int $piecesCount,
        public EstimateBasis $basis,
        // null for `community`
        public null|TimePredictionResult $prediction,
        // The puzzle's difficulty index count k
        public int $solvedIndices,
        public null|DifficultyTier $tier,
        public bool $tierEstimated,
        // The difficulty score the tier came from (measured, shrunk or the picture's)
        public null|float $difficulty = null,
        // `community` only: the median pace at the piece count and how many puzzlers it rests on
        public null|int $communitySeconds = null,
        public int $communityPlayers = 0,
    ) {
    }

    public function seconds(): int
    {
        return $this->prediction->predictedSeconds ?? $this->communitySeconds ?? 0;
    }

    /**
     * The time as shown: rounded (EstimateTime), always inside the shown window
     */
    public function roundedSeconds(): int
    {
        return EstimateTime::rounded($this->seconds(), $this->window());
    }

    /**
     * The shown 50 % "likely" window (Phase 3c rounding); null for `community`
     */
    public function window(): null|PredictionWindow
    {
        if ($this->prediction === null) {
            return null;
        }

        return PredictionWindow::rounded($this->prediction->rangeLowSeconds, $this->prediction->rangeHighSeconds, $this->prediction->predictedSeconds);
    }

    /**
     * The shown 80 % range; null for `community` and for a prediction without one
     */
    public function range80(): null|PredictionWindow
    {
        $prediction = $this->prediction;

        if ($prediction === null || $prediction->range80LowSeconds === null || $prediction->range80HighSeconds === null) {
            return null;
        }

        return PredictionWindow::rounded($prediction->range80LowSeconds, $prediction->range80HighSeconds, $prediction->predictedSeconds);
    }

    /**
     * The rounding step of the shown time (EstimateTime::stepFor()) - a difference smaller than it says nothing
     */
    public function stepSeconds(): int
    {
        return EstimateTime::stepFor($this->seconds());
    }

    public function isEstimated(): bool
    {
        return $this->basis->isEstimated();
    }

    public function isCommunity(): bool
    {
        return $this->basis === EstimateBasis::Community;
    }

    /**
     * The 80 % range (raw ends) relative to the time; null without one
     */
    public function relativeWidth(): null|float
    {
        $prediction = $this->prediction;

        if ($prediction === null || $prediction->predictedSeconds <= 0) {
            return null;
        }

        $low = $prediction->range80LowSeconds ?? $prediction->rangeLowSeconds;
        $high = $prediction->range80HighSeconds ?? $prediction->rangeHighSeconds;

        return max(0, $high - $low) / $prediction->predictedSeconds;
    }

    public function certainty(): null|EstimateCertainty
    {
        $width = $this->relativeWidth();

        return $width !== null ? EstimateCertainty::fromRelativeWidth($width) : null;
    }

    /**
     * Where the 80 % range sits on the certainty bar (the time × 0.5 … × 1.5, the time in the middle), as the CSS
     * insets of its segment in percent of the bar: wide = unsure. Null without a range.
     *
     * @return null|array{left: int, right: int}
     */
    public function bar(): null|array
    {
        $prediction = $this->prediction;

        if ($prediction === null || $prediction->predictedSeconds <= 0) {
            return null;
        }

        $low = ($prediction->range80LowSeconds ?? $prediction->rangeLowSeconds) / $prediction->predictedSeconds;
        $high = ($prediction->range80HighSeconds ?? $prediction->rangeHighSeconds) / $prediction->predictedSeconds;
        $span = self::BAR_TO - self::BAR_FROM;

        $left = (int) round(max(0.0, min(0.48, ($low - self::BAR_FROM) / $span)) * 100);
        $right = (int) round(max(0.0, min(0.48, (self::BAR_TO - $high) / $span)) * 100);

        return ['left' => $left, 'right' => $right];
    }

    /**
     * How many solves the time rests on: the player's own (`your_solves`), else the puzzle's difficulty indices
     */
    public function basisCount(): int
    {
        if ($this->basis === EstimateBasis::YourSolves) {
            return $this->prediction->personalSolveCount ?? 0;
        }

        if ($this->basis === EstimateBasis::Community) {
            return $this->communityPlayers;
        }

        return $this->solvedIndices;
    }
}
