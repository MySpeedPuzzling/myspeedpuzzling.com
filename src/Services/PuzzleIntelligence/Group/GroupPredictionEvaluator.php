<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Group;

use SpeedPuzzling\Web\Value\GroupCoordination;
use SpeedPuzzling\Web\Value\GroupModelParameters;

/**
 * The pair/team model on an honest time split (`myspeedpuzzling:evaluate-group-predictions --fit-before=`): fitted on
 * the results solved before the cut, every result from the cut on predicted as of its moment (its pair/team's
 * earlier results, its members' paces and the puzzle's difficulty before it - nothing later). Per group of results: the
 * error (mean |ln actual ÷ predicted|), the median bias, the windows' hits and median width; next to it the
 * coordination rule with the model's buckets alone (no synergy, no offsets, no own earlier time) and the reference -
 * GroupExpectation's rule as of the solve, on the results it can predict (every member known, a rated puzzle) with the
 * model's error on the same results. And the synergy's reliability: the split-half correlation of the pairs'/teams'
 * mean residual against the as-of coordination rule. Pure.
 */
final class GroupPredictionEvaluator
{
    public const int RELIABILITY_MINIMUM_RESULTS = 8;

    /** The groups in the order they are printed */
    public const array ORDER = [
        'first tries',
        'first: pairs', 'first: teams of 3', 'first: teams of 4+',
        'first: experience 0', 'first: experience 1-2', 'first: experience 3-9', 'first: experience 10+',
        'first: unknown members 0', 'first: unknown members 1', 'first: unknown members 2+',
        'first: difficulty solves', 'first: difficulty shrunk', 'first: difficulty image', 'first: difficulty none',
        'first: at an event',
        'repeats', 'repeats: own earlier time', 'repeats: model',
    ];

    public function __construct(
        private readonly GroupPredictionModel $model = new GroupPredictionModel(),
    ) {
    }

    /**
     * @param list<string> $off
     * @return array{parameters: GroupModelParameters, groups: array<string, array<string, null|int|float>>, reliability: array<string, array<string, null|int|float>>}
     */
    public function evaluate(GroupResultSet $set, float $cut, null|float $until, float $tau, array $off = []): array
    {
        $parameters = $this->model->fit($set, $cut, $tau, $off);
        $replay = $this->model->replay($set, $parameters);

        /** @var array<string, list<int>> $groups */
        $groups = [];

        for ($i = 0, $count = $set->count(); $i < $count; $i++) {
            if ($replay->centre[$i] === null || $set->solved[$i] < $cut || ($until !== null && $set->solved[$i] >= $until)) {
                continue;
            }

            foreach (self::groupsOf($set, $replay, $i) as $group) {
                $groups[$group][] = $i;
            }
        }

        $metrics = [];

        foreach (self::ORDER as $group) {
            if (isset($groups[$group])) {
                $metrics[$group] = self::metrics($set, $replay, $groups[$group]);
            }
        }

        return [
            'parameters' => $parameters,
            'groups' => $metrics,
            'reliability' => self::reliability($set, $this->model->replay($set, $parameters, withWindows: false)),
        ];
    }

    /**
     * @return list<string>
     */
    private static function groupsOf(GroupResultSet $set, GroupReplay $replay, int $i): array
    {
        if ($replay->kind[$i] !== GroupReplay::KIND_FIRST) {
            return ['repeats', $replay->kind[$i] === GroupReplay::KIND_OWN ? 'repeats: own earlier time' : 'repeats: model'];
        }

        $groups = [
            'first tries',
            match (GroupCoordination::sizeGroup($set->members[$i])) {
                2 => 'first: pairs',
                3 => 'first: teams of 3',
                default => 'first: teams of 4+',
            },
            'first: experience ' . GroupPredictionModel::experienceBucket($replay->experience[$i]),
            'first: unknown members ' . ($set->unknown[$i] >= 2 ? '2+' : (string) $set->unknown[$i]),
            'first: difficulty ' . $replay->source[$i],
        ];

        if ($set->competition[$i]) {
            $groups[] = 'first: at an event';
        }

        return $groups;
    }

    /**
     * @param list<int> $rows
     * @return array<string, null|int|float>
     */
    private static function metrics(GroupResultSet $set, GroupReplay $replay, array $rows): array
    {
        $errors = [];
        $absolute = 0.0;
        $rule = 0.0;
        $hit50 = 0;
        $hit80 = 0;
        $windows = 0;
        $widths = [];
        $reference = 0.0;
        $modelOnReference = 0.0;
        $referenceRows = 0;
        $referenceHit50 = 0;
        $referenceHit80 = 0;

        foreach ($rows as $i) {
            $y = log($set->seconds[$i]);
            $error = $y - (float) $replay->centre[$i];
            $errors[] = $error;
            $absolute += abs($error);
            $rule += abs($y - (float) $replay->base[$i]);
            $window = $replay->window[$i];

            if ($window !== null) {
                $windows++;
                $hit50 += $y >= $window[1] && $y <= $window[2] ? 1 : 0;
                $hit80 += $y >= $window[0] && $y <= $window[3] ? 1 : 0;
                $widths[] = exp($window[2] - $window[1]) - 1.0;
            }

            $baselineCentre = $replay->baselineCentre[$i];
            $baselineWindow = $replay->baselineWindow[$i];

            if ($baselineCentre !== null) {
                $referenceRows++;
                $reference += abs($y - $baselineCentre);
                $modelOnReference += abs($error);

                if ($baselineWindow !== null) {
                    $referenceHit50 += $y >= $baselineWindow[1] && $y <= $baselineWindow[2] ? 1 : 0;
                    $referenceHit80 += $y >= $baselineWindow[0] && $y <= $baselineWindow[3] ? 1 : 0;
                }
            }
        }

        $n = count($rows);

        return [
            'rows' => $n,
            'error' => round($absolute / $n, 4),
            'bias' => round(GroupPredictionModel::median($errors), 4),
            'ruleError' => round($rule / $n, 4),
            'window50Hit' => $windows > 0 ? round($hit50 / $windows, 4) : null,
            'window80Hit' => $windows > 0 ? round($hit80 / $windows, 4) : null,
            'window50Width' => $widths !== [] ? round(GroupPredictionModel::median($widths), 4) : null,
            'referenceRows' => $referenceRows,
            'referenceError' => $referenceRows > 0 ? round($reference / $referenceRows, 4) : null,
            'errorOnReferenceRows' => $referenceRows > 0 ? round($modelOnReference / $referenceRows, 4) : null,
            'referenceWindow50Hit' => $referenceRows > 0 ? round($referenceHit50 / $referenceRows, 4) : null,
            'referenceWindow80Hit' => $referenceRows > 0 ? round($referenceHit80 / $referenceRows, 4) : null,
        ];
    }

    /**
     * The synergy's reliability over every result (the whole history, as of each solve): per pair/team with at least
     * RELIABILITY_MINIMUM_RESULTS results, the mean residual against the coordination rule of its odd and of its even
     * results (in the order of the solves) - their correlation (raw and Spearman-Brown for the full length) and the
     * spread of the pairs'/teams' means.
     *
     * @return array<string, array<string, null|int|float>>
     */
    public static function reliability(GroupResultSet $set, GroupReplay $replay): array
    {
        $subsets = [
            'all results' => static fn (int $i): bool => true,
            'first tries' => static fn (int $i): bool => $set->first[$i],
            'pairs, 500 pieces, first tries, every member known' => static fn (int $i): bool => $set->first[$i] && $set->pieces[$i] === 500 && $set->members[$i] === 2 && $set->unknown[$i] === 0,
            'every member known' => static fn (int $i): bool => $set->unknown[$i] === 0,
            'with unknown members' => static fn (int $i): bool => $set->unknown[$i] > 0,
        ];
        $reliability = [];

        foreach ($subsets as $name => $belongs) {
            /** @var array<int, list<float>> $byTeam */
            $byTeam = [];

            for ($i = 0, $count = $set->count(); $i < $count; $i++) {
                $base = $replay->base[$i];

                if ($base !== null && $belongs($i)) {
                    $byTeam[$set->team[$i]][] = log($set->seconds[$i]) - $base;
                }
            }

            $odd = [];
            $even = [];
            $means = [];

            foreach ($byTeam as $residuals) {
                if (count($residuals) < self::RELIABILITY_MINIMUM_RESULTS) {
                    continue;
                }

                $oddSum = 0.0;
                $evenSum = 0.0;

                foreach ($residuals as $position => $residual) {
                    if ($position % 2 === 0) {
                        $oddSum += $residual;
                    } else {
                        $evenSum += $residual;
                    }
                }

                $odd[] = $oddSum / intdiv(count($residuals) + 1, 2);
                $even[] = $evenSum / intdiv(count($residuals), 2);
                $means[] = array_sum($residuals) / count($residuals);
            }

            $correlation = self::correlation($odd, $even);
            $reliability[$name] = [
                'teams' => count($means),
                'splitHalf' => $correlation !== null ? round($correlation, 4) : null,
                'spearmanBrown' => $correlation !== null ? round(2 * $correlation / (1 + $correlation), 4) : null,
                'sdOfTeamMeans' => count($means) >= 2 ? round(self::standardDeviation($means), 4) : null,
            ];
        }

        return $reliability;
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private static function correlation(array $a, array $b): null|float
    {
        $n = count($a);

        if ($n < 3) {
            return null;
        }

        $meanA = array_sum($a) / $n;
        $meanB = array_sum($b) / $n;
        $covariance = 0.0;
        $varianceA = 0.0;
        $varianceB = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $covariance += ($a[$i] - $meanA) * ($b[$i] - $meanB);
            $varianceA += ($a[$i] - $meanA) ** 2;
            $varianceB += ($b[$i] - $meanB) ** 2;
        }

        return $varianceA > 0 && $varianceB > 0 ? $covariance / sqrt($varianceA * $varianceB) : null;
    }

    /**
     * @param list<float> $values
     */
    private static function standardDeviation(array $values): float
    {
        $mean = array_sum($values) / count($values);

        return sqrt(array_sum(array_map(static fn (float $value): float => ($value - $mean) ** 2, $values)) / count($values));
    }
}
