<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Value\InsightsProfileAxis;

/**
 * The raw scores of one player's puzzler profile card (Value\InsightsProfileAxis), pure: the prototype's
 * renderProfile() on real results. Definitions with their reasons: docs/features/insights/computation.md.
 *
 * - ratio = time ÷ the puzzle's median solo time
 * - form at a result = median ratio of the player's first tries within ±75 days of it ("your usual" then)
 * - residual = ratio ÷ form ÷ the community's learning factor for that attempt (attempt 2 is ~12 % faster for
 *   everybody, so a repeat is not a "strength") - 1.0 = exactly your usual, 0.9 = 10 % faster
 */
final class InsightsProfileCalculator
{
    public const int DAY = 86_400;

    private const int FORM_HALF_WINDOW = 75 * self::DAY;
    private const int RECENT = 365 * self::DAY;
    private const int FORM_NOW = 45 * self::DAY;
    private const int FORM_THEN_FROM = 395 * self::DAY;
    private const int FORM_THEN_TO = 335 * self::DAY;
    private const int BIG_PUZZLE_PIECES = 1000;
    /** Difficulty tiers Hard and Very hard (Value\DifficultyTier) */
    private const int HARD_TIER = 5;

    /**
     * @param list<array{puzzle_id: string, seconds: int, at: int, first_attempt: bool, attempt: int, pieces: int, median: int, tier: null|int, event: bool}> $results
     *        one player's solo results, oldest first; `at` = solve moment (unix seconds), `attempt` = 1-based number of
     *        the result among the player's results on the puzzle, `median` = the puzzle's median solo time
     * @param callable(int $pieces, int $attempt): float $learningFactor cumulative community learning factor, 1.0 for attempt 1
     * @return array<string, array{score: null|float, sample_size: int}> by InsightsProfileAxis value
     */
    public function scores(array $results, int $now, callable $learningFactor): array
    {
        $count = count($results);
        $ratios = [];
        $firstTries = [];

        foreach ($results as $i => $result) {
            $ratios[$i] = $result['seconds'] / $result['median'];

            if ($result['first_attempt']) {
                $firstTries[] = $i;
            }
        }

        $forms = $this->forms($results, $ratios, $firstTries);
        $residuals = [];

        foreach ($results as $i => $result) {
            if ($forms[$i] !== null) {
                $residuals[$i] = $ratios[$i] / $forms[$i] / $learningFactor($result['pieces'], min($result['attempt'], 4));
            }
        }

        $recentRatios = [];
        $recentResiduals = [];
        $hardResiduals = [];
        $bigResiduals = [];
        $eventResiduals = [];
        $formsNow = [];
        $formsThen = [];
        /** @var array<string, array<int, int>> $byPuzzle puzzle => attempt => result index */
        $byPuzzle = [];

        for ($i = 0; $i < $count; $i++) {
            $result = $results[$i];
            $age = $now - $result['at'];
            $isRecent = $age <= self::RECENT;
            $residual = $residuals[$i] ?? null;

            if ($isRecent) {
                $recentRatios[] = $ratios[$i];
            }

            if ($residual !== null) {
                if ($isRecent) {
                    $recentResiduals[] = $residual;

                    if ($result['tier'] !== null && $result['tier'] >= self::HARD_TIER) {
                        $hardResiduals[] = $residual;
                    }
                }

                if ($result['pieces'] >= self::BIG_PUZZLE_PIECES) {
                    $bigResiduals[] = $residual;
                }

                if ($result['event']) {
                    $eventResiduals[] = $residual;
                }
            }

            if ($forms[$i] !== null) {
                if ($age <= self::FORM_NOW) {
                    $formsNow[] = $forms[$i];
                } elseif ($age >= self::FORM_THEN_TO && $age <= self::FORM_THEN_FROM) {
                    $formsThen[] = $forms[$i];
                }
            }

            if ($result['attempt'] <= 2) {
                $byPuzzle[$result['puzzle_id']][$result['attempt']] = $i;
            }
        }

        $secondAttemptGains = [];

        foreach ($byPuzzle as $attempts) {
            if (!isset($attempts[1], $attempts[2])) {
                continue;
            }

            [$first, $second] = [$attempts[1], $attempts[2]];

            if ($forms[$first] === null || $forms[$second] === null) {
                continue;
            }

            $secondAttemptGains[] = ($results[$second]['seconds'] / $results[$first]['seconds']) * ($forms[$first] / $forms[$second]);
        }

        $improvementSamples = min(count($formsNow), count($formsThen));
        $improvement = $improvementSamples >= InsightsProfileAxis::Improvement->minimumSamples()
            ? 1.0 - self::median($formsNow) / self::median($formsThen)
            : null;

        $consistency = count($recentResiduals) >= InsightsProfileAxis::Consistency->minimumSamples()
            ? self::quantile($recentResiduals, 0.75) / self::quantile($recentResiduals, 0.25)
            : null;

        return [
            InsightsProfileAxis::Speed->value => self::medianScore($recentRatios, InsightsProfileAxis::Speed),
            InsightsProfileAxis::Improvement->value => ['score' => $improvement, 'sample_size' => count($formsNow) + count($formsThen)],
            InsightsProfileAxis::Consistency->value => ['score' => $consistency, 'sample_size' => count($recentResiduals)],
            InsightsProfileAxis::Learning->value => count($secondAttemptGains) >= InsightsProfileAxis::Learning->minimumSamples()
                ? ['score' => 1.0 - self::median($secondAttemptGains), 'sample_size' => count($secondAttemptGains)]
                : ['score' => null, 'sample_size' => count($secondAttemptGains)],
            InsightsProfileAxis::HardPuzzles->value => self::medianScore($hardResiduals, InsightsProfileAxis::HardPuzzles),
            InsightsProfileAxis::BigPuzzles->value => self::medianScore($bigResiduals, InsightsProfileAxis::BigPuzzles),
            InsightsProfileAxis::Events->value => self::medianScore($eventResiduals, InsightsProfileAxis::Events),
        ];
    }

    /**
     * Rolling median of first-try ratios within ±75 days of every result, with one sorted window sliding along the
     * results (they are ordered by time) - O(n · w) instead of sorting a window per result.
     *
     * @param list<array{puzzle_id: string, seconds: int, at: int, first_attempt: bool, attempt: int, pieces: int, median: int, tier: null|int, event: bool}> $results
     * @param array<int, float> $ratios
     * @param list<int> $firstTries indexes of first tries, oldest first
     * @return array<int, null|float>
     */
    private function forms(array $results, array $ratios, array $firstTries): array
    {
        $forms = [];
        /** @var list<float> $window sorted */
        $window = [];
        $nextToAdd = 0;
        $nextToRemove = 0;
        $firstTryCount = count($firstTries);

        foreach ($results as $i => $result) {
            while ($nextToAdd < $firstTryCount && $results[$firstTries[$nextToAdd]]['at'] <= $result['at'] + self::FORM_HALF_WINDOW) {
                $value = $ratios[$firstTries[$nextToAdd]];
                array_splice($window, self::insertionPoint($window, $value), 0, [$value]);
                $nextToAdd++;
            }

            while ($nextToRemove < $nextToAdd && $results[$firstTries[$nextToRemove]]['at'] < $result['at'] - self::FORM_HALF_WINDOW) {
                $value = $ratios[$firstTries[$nextToRemove]];
                array_splice($window, self::insertionPoint($window, $value), 1);
                $nextToRemove++;
            }

            $forms[$i] = $window === [] ? null : self::medianOfSorted($window);
        }

        return $forms;
    }

    /**
     * @param list<float> $values
     * @return array{score: null|float, sample_size: int}
     */
    private static function medianScore(array $values, InsightsProfileAxis $axis): array
    {
        return [
            'score' => count($values) >= $axis->minimumSamples() ? self::median($values) : null,
            'sample_size' => count($values),
        ];
    }

    /**
     * First index whose value is >= $value
     *
     * @param list<float> $sorted
     */
    private static function insertionPoint(array $sorted, float $value): int
    {
        $low = 0;
        $high = count($sorted);

        while ($low < $high) {
            $middle = ($low + $high) >> 1;

            if ($sorted[$middle] < $value) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /**
     * @param list<float> $values
     */
    public static function median(array $values): float
    {
        sort($values);

        return self::medianOfSorted($values);
    }

    /**
     * @param list<float> $sorted not empty
     */
    private static function medianOfSorted(array $sorted): float
    {
        $count = count($sorted);
        $middle = $count >> 1;

        return $count % 2 === 1 ? $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2;
    }

    /**
     * Linear interpolation between the closest ranks, as the prototype's quant()
     *
     * @param list<float> $values not empty
     */
    public static function quantile(array $values, float $q): float
    {
        sort($values);
        $position = (count($values) - 1) * $q;
        $low = (int) floor($position);
        $high = (int) ceil($position);

        return $values[$low] + ($values[$high] - $values[$low]) * ($position - $low);
    }
}
