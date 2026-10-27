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
    /** @var array<string, array{errors: list<float>, hit50: int, hit80: int, widths50: list<float>, widths80: list<float>, score80: float}> */
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

        $this->groups[$label] ??= ['errors' => [], 'hit50' => 0, 'hit80' => 0, 'widths50' => [], 'widths80' => [], 'score80' => 0.0];
        $group = &$this->groups[$label];

        // The 80 % window's interval score in logs: its width + 10 × how far outside it the time came (lower = better)
        if ($low80 > 0 && $high80 >= $low80) {
            $group['score80'] += log($high80 / $low80) + 10.0 * max(0.0, log($low80 / $seconds)) + 10.0 * max(0.0, log($seconds / $high80));
        }

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
     * The experience bucket of a player by their qualifying first attempts before the solve (every size) - the review's
     * buckets: the candidate's parts help the most experienced players most, and must never hurt the new ones.
     */
    public static function experienceBucket(int $firstTries): string
    {
        return match (true) {
            $firstTries < 10 => '0-9 first tries',
            $firstTries < 30 => '10-29 first tries',
            $firstTries < 100 => '30-99 first tries',
            $firstTries < 300 => '100-299 first tries',
            default => '300+ first tries',
        };
    }

    /**
     * One line per measured group: n, MAE, 50 / 80 % hits - the ablation's compact form, the overall group ("all") and
     * the k buckets ("k …") as columns.
     *
     * @return array<string, int|float|string>
     */
    public function compact(): array
    {
        $row = [];

        foreach ($this->groups as $label => $group) {
            $n = count($group['errors']);
            $row[$label === 'all' ? 'mae' : 'mae ' . $label] = round(array_sum(array_map(abs(...), $group['errors'])) / $n, 4);

            if ($label === 'all') {
                $row['n'] = $n;
                $row['hit50'] = round(100 * $group['hit50'] / $n, 1);
                $row['hit80'] = round(100 * $group['hit80'] / $n, 1);
                $row['width50'] = round(100 * self::median($group['widths50']), 1);
            }
        }

        ksort($row);

        return $row;
    }

    /**
     * Several predictors measured on the same rows, one line per group: n, then each predictor's MAE, median bias and
     * 50 / 80 % hits, and the change of each predictor's MAE against the first one.
     *
     * @param array<string, self> $byPredictor the first one is the reference
     * @return list<array<string, int|float|string>>
     */
    public static function sideBySide(array $byPredictor): array
    {
        $reference = array_key_first($byPredictor);
        $rows = [];

        if ($reference === null) {
            return [];
        }

        $labels = array_keys($byPredictor[$reference]->groups);
        sort($labels);

        foreach ($labels as $label) {
            $referenceErrors = $byPredictor[$reference]->groups[$label]['errors'];
            $referenceMae = array_sum(array_map(abs(...), $referenceErrors)) / count($referenceErrors);
            $row = ['group' => $label, 'n' => count($referenceErrors)];

            foreach ($byPredictor as $name => $groups) {
                $group = $groups->groups[$label] ?? null;

                if ($group === null) {
                    continue;
                }

                $n = count($group['errors']);
                $mae = array_sum(array_map(abs(...), $group['errors'])) / $n;
                $row[$name . ' mae'] = round($mae, 4);
                $row[$name . ' mae6'] = round($mae, 6);
                $row[$name . ' is80'] = round($group['score80'] / $n, 5);

                if ($name !== $reference) {
                    $row[$name . ' Δmae'] = round(100 * ($mae / $referenceMae - 1), 1) . ' %';
                }

                $row[$name . ' bias'] = round(100 * (exp(self::median($group['errors'])) - 1), 1) . ' %';
                $row[$name . ' hit50'] = round(100 * $group['hit50'] / $n, 1);
                $row[$name . ' hit80'] = round(100 * $group['hit80'] / $n, 1);
            }

            $rows[] = $row;
        }

        return $rows;
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
