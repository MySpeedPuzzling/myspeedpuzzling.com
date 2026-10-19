<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Model;

/**
 * Accuracy and calibration of predictions, per labelled group (PredictionEvaluator): error = |ln(actual ÷
 * predicted)|, bias = median ln(actual ÷ predicted) (negative = faster than predicted), hit = actual inside the window,
 * width = (high - low) ÷ predicted.
 */
final class EvaluationGroups
{
    /** @var array<string, array{errors: list<float>, hit50: int, hit80: int, widths50: list<float>, widths80: list<float>}> */
    private array $groups = [];

    private int $attempts = 0;
    private int $predictedByTodaysRule = 0;

    public function count(bool $predictedByTodaysRule): void
    {
        $this->attempts++;

        if ($predictedByTodaysRule) {
            $this->predictedByTodaysRule++;
        }
    }

    public function add(string $label, int $seconds, int $predicted, int $low50, int $high50, int $low80, int $high80): void
    {
        if ($seconds <= 0 || $predicted <= 0) {
            return;
        }

        $this->groups[$label] ??= ['errors' => [], 'hit50' => 0, 'hit80' => 0, 'widths50' => [], 'widths80' => []];
        $group = &$this->groups[$label];

        $group['errors'][] = log($seconds / $predicted);
        $group['hit50'] += $seconds >= $low50 && $seconds <= $high50 ? 1 : 0;
        $group['hit80'] += $seconds >= $low80 && $seconds <= $high80 ? 1 : 0;
        $group['widths50'][] = ($high50 - $low50) / $predicted;
        $group['widths80'][] = ($high80 - $low80) / $predicted;
    }

    /**
     * @return list<array<string, int|float|string>>
     */
    public function report(): array
    {
        $report = [];

        if ($this->attempts > 0) {
            $report[] = [
                'group' => 'attempts after the cut-off',
                'n' => $this->attempts,
                'coverage' => round(100 * $this->predictedByTodaysRule / $this->attempts, 1) . ' % by the v2 rule (k >= 5 and a baseline)',
            ];
        }

        foreach ($this->groups as $label => $group) {
            $n = count($group['errors']);
            $errors = $group['errors'];
            $absolute = array_map(abs(...), $errors);

            $report[] = [
                'group' => $label,
                'n' => $n,
                'coverage' => $this->attempts > 0 ? round(100 * $n / $this->attempts, 1) . ' %' : '-',
                'mae' => round(array_sum($absolute) / $n, 4),
                'median_bias' => round(100 * (exp(self::median($errors)) - 1), 2) . ' %',
                'hit50' => round(100 * $group['hit50'] / $n, 1) . ' %',
                'width50' => round(100 * self::median($group['widths50']), 1) . ' %',
                'hit80' => round(100 * $group['hit80'] / $n, 1) . ' %',
                'width80' => round(100 * self::median($group['widths80']), 1) . ' %',
            ];
        }

        return $report;
    }

    /**
     * @param list<float> $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 0 ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle];
    }
}
