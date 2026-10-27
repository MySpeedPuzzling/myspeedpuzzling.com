<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation;

use SpeedPuzzling\Web\Value\StoredPredictionWindows;

/**
 * Skill of a candidate prediction against a reference on the same solves (docs/features/insights/image-estimate-gates.md,
 * "Skill"): mean |ln(actual ÷ predicted)| of both, their paired difference with its standard error, and - for the
 * windows - the median 80 % width each needs to hold exactly 80 % of these solves ("width at equal coverage": a
 * narrower window that holds as many solves is sharper, a narrower one that holds fewer is not).
 */
final class PairedSkill
{
    private const int DECIMALS = 4;

    private int $rows = 0;
    private float $candidateError = 0.0;
    private float $referenceError = 0.0;
    private float $difference = 0.0;
    private float $differenceSquared = 0.0;

    /** @var array{candidate: array{0: list<float>, 1: list<float>, 2: list<float>, 3: int}, reference: array{0: list<float>, 1: list<float>, 2: list<float>, 3: int}} from, to, log width, never held */
    private array $widths = ['candidate' => [[], [], [], 0], 'reference' => [[], [], [], 0]];

    public function __construct(
        private readonly bool $withWidths = false,
    ) {
    }

    public function add(int $actualSeconds, StoredPredictionWindows $candidate, StoredPredictionWindows $reference): void
    {
        $candidateError = $candidate->error($actualSeconds);
        $referenceError = $reference->error($actualSeconds);
        $difference = $candidateError - $referenceError;

        $this->rows++;
        $this->candidateError += $candidateError;
        $this->referenceError += $referenceError;
        $this->difference += $difference;
        $this->differenceSquared += $difference * $difference;

        if ($this->withWidths) {
            foreach (['candidate' => $candidate, 'reference' => $reference] as $side => $windows) {
                $interval = WindowScaling::scaleInterval($actualSeconds, $windows->predictedSeconds, $windows->range80Low, $windows->range80High);

                if ($interval === null) {
                    $this->widths[$side][3]++;
                } else {
                    $this->widths[$side][0][] = $interval[0];
                    $this->widths[$side][1][] = $interval[1];
                }

                $this->widths[$side][2][] = $windows->logWidth(80);
            }
        }
    }

    public function rows(): int
    {
        return $this->rows;
    }

    /**
     * @return array{rows: int, maeCandidate: null|float, maeReference: null|float, errorRatio: null|float, meanDifference: null|float, differenceSe: null|float, width80Candidate?: null|float, width80Reference?: null|float}
     */
    public function metrics(): array
    {
        $n = $this->rows;
        $metrics = [
            'rows' => $n,
            'maeCandidate' => $n > 0 ? round($this->candidateError / $n, self::DECIMALS) : null,
            'maeReference' => $n > 0 ? round($this->referenceError / $n, self::DECIMALS) : null,
            'errorRatio' => $n > 0 && $this->referenceError > 0.0 ? round($this->candidateError / $this->referenceError, self::DECIMALS) : null,
            'meanDifference' => $n > 0 ? round($this->difference / $n, 5) : null,
            'differenceSe' => null,
        ];

        if ($n > 1) {
            $mean = $this->difference / $n;
            $variance = max(0.0, ($this->differenceSquared - $n * $mean * $mean) / ($n - 1));
            $metrics['differenceSe'] = round(sqrt($variance / $n), 5);
        }

        if ($this->withWidths) {
            $metrics['width80Candidate'] = self::widthAtEqualCoverage($this->widths['candidate'], $n);
            $metrics['width80Reference'] = self::widthAtEqualCoverage($this->widths['reference'], $n);
        }

        return $metrics;
    }

    /**
     * The median log width of the 80 % windows once scaled to hold exactly 80 % of the solves
     *
     * @param array{0: list<float>, 1: list<float>, 2: list<float>, 3: int} $widths
     */
    private static function widthAtEqualCoverage(array $widths, int $count): null|float
    {
        $scale = WindowScaling::fitScale($widths[0], $widths[1], $count, 0.80);

        if ($scale === null || $widths[2] === []) {
            return null;
        }

        $logWidths = $widths[2];
        sort($logWidths);
        $middle = intdiv(count($logWidths), 2);
        $median = count($logWidths) % 2 === 1 ? $logWidths[$middle] : ($logWidths[$middle - 1] + $logWidths[$middle]) / 2;

        return round($scale * $median, self::DECIMALS);
    }
}
