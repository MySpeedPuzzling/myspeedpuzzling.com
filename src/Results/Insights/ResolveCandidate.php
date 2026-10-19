<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PredictionWindow;

/**
 * A puzzle solved a year or more ago that would likely go clearly faster now (ResolveRadar).
 */
readonly final class ResolveCandidate
{
    public function __construct(
        public InsightsPuzzleRef $puzzle,
        public int $bestSeconds,
        public DateTimeImmutable $lastSolvedAt,
        public float $monthsSinceLastSolve,
        public int $attempts,
        /** The personal prediction of the next attempt (GetPlayerPredictions) */
        public int $predictedSeconds,
        public int $predictedLowSeconds,
        public int $predictedHighSeconds,
        /** 1 − predicted ÷ best: 0.12 = likely 12 % faster than the best */
        public float $likelyGain,
        public bool $onShelf,
        /** The puzzle's solo results (dot size) */
        public int $soloResultsCount,
    ) {
    }

    /**
     * The likely window of the next attempt as every surface shows it (PredictionWindow::rounded())
     */
    public function window(): PredictionWindow
    {
        return PredictionWindow::rounded($this->predictedLowSeconds, $this->predictedHighSeconds, $this->predictedSeconds);
    }
}
