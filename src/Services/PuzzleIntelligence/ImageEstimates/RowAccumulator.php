<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\ImageEstimates;

use SpeedPuzzling\Web\Value\ImageEstimateEvaluationRow;

/**
 * The running sums of one group of ImageEstimateEvaluator - what its metrics need and nothing per row beyond the two
 * widths (medians).
 */
final class RowAccumulator
{
    public int $rows = 0;
    public float $imageError = 0.0;
    public int $imageHit50 = 0;
    public int $imageHit80 = 0;
    /** @var list<float> */
    public array $imageWidth50 = [];
    /** @var list<float> */
    public array $imageWidth80 = [];

    public int $pairedRows = 0;
    public float $pairedImageError = 0.0;
    public float $pairedBlendError = 0.0;
    public float $pairedStatisticalError = 0.0;

    public int $blendRows = 0;
    public int $blendHit50 = 0;
    public int $blendHit80 = 0;
    /** @var list<float> */
    public array $blendWidth50 = [];
    /** @var list<float> */
    public array $blendWidth80 = [];

    /**
     * Per puzzle: the estimate (log) of its latest row, that row's time, its measured log difficulty (null = not
     * measured well enough)
     *
     * @var array<string, array{0: float, 1: float, 2: null|float}>
     */
    public array $puzzles = [];

    public function add(ImageEstimateEvaluationRow $row, int $measuredMinIndices): void
    {
        $actual = (float) $row->actualSeconds;

        $this->rows++;
        $imageError = abs(log($actual / max(1, $row->imageSeconds)));
        $this->imageError += $imageError;
        $this->imageHit50 += self::inside($row->actualSeconds, $row->imageRange50Low, $row->imageRange50High);
        $this->imageHit80 += self::inside($row->actualSeconds, $row->imageRange80Low, $row->imageRange80High);
        $this->imageWidth50[] = ($row->imageRange50High - $row->imageRange50Low) / max(1, $row->imageSeconds);
        $this->imageWidth80[] = ($row->imageRange80High - $row->imageRange80Low) / max(1, $row->imageSeconds);

        if ($row->blendSeconds !== null && $row->statisticalSeconds !== null) {
            $this->pairedRows++;
            $this->pairedImageError += $imageError;
            $this->pairedBlendError += abs(log($actual / max(1, $row->blendSeconds)));
            $this->pairedStatisticalError += abs(log($actual / max(1, $row->statisticalSeconds)));
        }

        if ($row->blendSeconds !== null && $row->blendRange50Low !== null && $row->blendRange50High !== null && $row->blendRange80Low !== null && $row->blendRange80High !== null) {
            $this->blendRows++;
            $this->blendHit50 += self::inside($row->actualSeconds, $row->blendRange50Low, $row->blendRange50High);
            $this->blendHit80 += self::inside($row->actualSeconds, $row->blendRange80Low, $row->blendRange80High);
            $this->blendWidth50[] = ($row->blendRange50High - $row->blendRange50Low) / max(1, $row->blendSeconds);
            $this->blendWidth80[] = ($row->blendRange80High - $row->blendRange80Low) / max(1, $row->blendSeconds);
        }

        $known = $this->puzzles[$row->puzzleId] ?? null;

        if ($known === null || $row->computedAt > $known[1]) {
            $measured = $row->measuredIndices >= $measuredMinIndices && $row->measuredScore !== null && $row->measuredScore > 0.0
                ? log($row->measuredScore)
                : null;
            $this->puzzles[$row->puzzleId] = [$row->imageLogDifficulty, $row->computedAt, $measured];
        }
    }

    private static function inside(int $actual, int $low, int $high): int
    {
        return $actual >= $low && $actual <= $high ? 1 : 0;
    }
}
