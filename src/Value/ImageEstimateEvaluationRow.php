<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One first try with a stored `image` prediction, as the evaluation of the estimates reads it
 * (GetImageEstimateEvaluationRows): the image row and, of the same time and model version, the blend and statistical
 * rows (null fields = that predictor had nothing), the actual time and the puzzle's measured difficulty now, when it
 * was solved, and the interval of the puzzle's current estimate (the tier range, gate v2).
 */
readonly final class ImageEstimateEvaluationRow
{
    public function __construct(
        public string $puzzleId,
        public PiecesGroup $sizeGroup,
        public ImageKind $imageKind,
        // The puzzle's difficulty index count when the solve was predicted
        public int $kIndices,
        public int $actualSeconds,
        // The estimate the image row rested on (log difficulty) and when that row was written
        public float $imageLogDifficulty,
        public float $computedAt,
        public int $imageSeconds,
        public int $imageRange50Low,
        public int $imageRange50High,
        public int $imageRange80Low,
        public int $imageRange80High,
        public null|int $blendSeconds,
        public null|int $blendRange50Low,
        public null|int $blendRange50High,
        public null|int $blendRange80Low,
        public null|int $blendRange80High,
        public null|int $statisticalSeconds,
        // The puzzle's measured difficulty now: its index count and the median (null below RATED_INDICES)
        public int $measuredIndices,
        public null|float $measuredScore,
        // The estimate generation the image row rested on: "<model> <model version>"
        public string $estimateModel = 'unknown',
        // When the puzzle was solved (finished_at, else tracked_at - epoch seconds): the out-of-period split
        public float $solvedAt = 0.0,
        // A live row (written when the time was added) - else reconstructed by the backfill
        public bool $live = false,
        // The statistical row's windows (null = it had nothing): the reference of the window width at equal coverage
        public null|int $statisticalRange80Low = null,
        public null|int $statisticalRange50Low = null,
        public null|int $statisticalRange50High = null,
        public null|int $statisticalRange80High = null,
        // The puzzle's current estimate interval around its estimate (log p10 − log, log p90 − log), null without one
        public null|float $estimateP10Offset = null,
        public null|float $estimateP90Offset = null,
    ) {
    }
}
