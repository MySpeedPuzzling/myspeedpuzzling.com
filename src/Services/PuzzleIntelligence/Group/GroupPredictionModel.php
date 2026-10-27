<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Group;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\GroupCoordination;
use SpeedPuzzling\Web\Value\GroupModelParameters;

/**
 * The pair/team prediction model (docs/features/puzzle-intelligence/README.md "Pair and team predictions") - never
 * shown, side by side only. Pure: works on a GroupResultSet, no database.
 *
 * ln t̂ = ln c(size, first try / repeat, unknown members) + ln D + ln 1 / R + s + o
 * - R = Σ 1 / (paceᵢ × e^driftᵢ) of the members with a pace before the solve, every unknown member (guest, no pace)
 *   counted with the mean rate of the known ones (R × members ÷ known);
 * - D = the puzzle's shown difficulty as of the solve: the median index from 5 indices on, 1-4 indices shrunk toward the
 *   picture estimate with the shown model's τ, none: the estimate alone, else the average puzzle;
 * - c = the median of t ÷ (D ÷ R) per bucket on the fit window (GroupExpectation's rule with unknown members);
 * - s = the pair/team's synergy as of the result: its earlier residuals, fading with a half-life, shrunk toward its
 *   members' offsets over all their pairs/teams with a pseudo-count κ (one for pairs/teams whose every member is known,
 *   one with unknown members - their unknown pace is part of what s learns), κ by a grid on the fit window's last 91 days;
 * - o = shrunk centre offsets per the pair/team's experience, the difficulty's source and whether it was at an event;
 * - a repeat after the pair/team's own earlier time on the puzzle: that time × a fitted ratio instead.
 * Windows: the residual's scale per factor level (raking), quantiles of the scaled residual per kind - out of the fit
 * window's sequential (as-of) residuals.
 *
 * The reference (`group_baseline`): GroupExpectation's coordination rule alone, as of the solve - every member a
 * registered player with a pace before it, a rated puzzle (5+ indices), c per size × first try / repeat, no drift.
 */
final class GroupPredictionModel
{
    public const int MODEL_VERSION = 1;

    /** Results a coordination bucket needs, else the bucket without unknown members, else the seed */
    public const int MINIMUM_BUCKET = GroupCoordination::MINIMUM_SAMPLES;
    /** Centre offsets: median × n ÷ (n + this) */
    public const float OFFSET_SHRINK = 200.0;
    public const int MINIMUM_SCALE_LEVEL = 100;
    public const int SCALE_ROUNDS = 8;
    /**
     * The window's factors: kind, the difficulty's source, unknown members, experience, size. Not "at an event": its
     * scale (0.94) narrowed windows that are too narrow at events out of period already (80 % hits 76.8 → 74.1 / 78.1 →
     * 75.4 on the two splits) - the event keeps its centre offset only
     */
    public const int SCALE_FACTORS = 5;
    public const int MINIMUM_QUANTILE_ROWS = 30;
    public const float MEMBER_KAPPA = 5.0;
    public const float HALF_LIFE_DAYS = 180.0;
    public const int VALIDATION_DAYS = 91;
    /** @var list<float> */
    public const array KAPPA_KNOWN_GRID = [1.0, 2.0, 3.0, 5.0, 8.0, 12.0, 20.0, 32.0, 50.0];
    /** @var list<float> */
    public const array KAPPA_UNKNOWN_GRID = [0.25, 0.5, 1.0, 2.0, 3.0, 5.0];
    public const float DEFAULT_KAPPA_KNOWN = 3.0;
    public const float DEFAULT_KAPPA_UNKNOWN = 1.0;
    /** @var list<float> the window's quantiles: 80 % low, 50 % low, 50 % high, 80 % high */
    public const array QUANTILES = [0.10, 0.25, 0.75, 0.90];

    private const float DAY = 86400.0;

    /**
     * What a time's group prediction was made for: when it changes (another pair/team, puzzle, day, first-try flag,
     * unboxed, a secret puzzle revealed) the stored rows are made again.
     */
    public static function fingerprint(string $teamId, string $puzzleId, int $pieces, float $solved, bool $first, bool $unboxed, bool $secret): string
    {
        return md5(implode('|', [self::MODEL_VERSION, $teamId, $puzzleId, $pieces, sprintf('%.0f', $solved), (int) $first, (int) $unboxed, (int) $secret]));
    }

    public static function experienceBucket(int $earlier): string
    {
        return match (true) {
            $earlier <= 0 => '0',
            $earlier <= 2 => '1-2',
            $earlier <= 9 => '3-9',
            default => '10+',
        };
    }

    public static function windowBucket(string $kind, int $earlier, int $unknown, string $source, int $size): string
    {
        return sprintf('%s|e%s|u%d|%s|%d', $kind, self::experienceBucket($earlier), min($unknown, 2), $source, $size);
    }

    /**
     * The gate's slices of a stored group row (its window bucket): size, the pair/team's experience, unknown members, and
     * whether a repeat came after the pair/team's own earlier time.
     *
     * @return list<string>
     */
    public static function slicesOfBucket(string $bucket): array
    {
        $parts = explode('|', $bucket);

        if (count($parts) !== 5) {
            return [];
        }

        [$kind, $experience, $unknown, , $size] = $parts;
        $slices = ['size ' . $size, 'experience ' . substr($experience, 1), 'unknown members ' . substr($unknown, 1)];

        if ($kind === GroupReplay::KIND_OWN) {
            $slices[] = 'own earlier time';
        }

        return $slices;
    }

    /**
     * The puzzle's log difficulty as of the result and what it rests on - the shown model's rule
     * (TimePredictionCalculator::logDifficulty()) with the picture estimate as the prior.
     *
     * @return array{0: float, 1: string}
     */
    public static function logDifficulty(GroupResultSet $set, int $i, float $tau): array
    {
        $image = $set->imageLog[$i];
        $shown = TimePredictionCalculator::logDifficulty($set->k[$i], $set->sumLog[$i], $set->score[$i], $image, $tau);

        if ($shown !== null) {
            return [$shown[0], $shown[1]->value];
        }

        return $set->k[$i] >= TimePredictionCalculator::RATED_INDICES || $image === null ? [0.0, 'none'] : [$image, 'image'];
    }

    /**
     * Fits the model on the results solved before $cut (all of them for the daily fit); every result after it is then
     * predicted as of its moment by replay() - nothing of it was seen.
     *
     * @param list<string> $off parts switched off (GroupModelParameters::PARTS)
     */
    public function fit(GroupResultSet $set, float $cut, float $tau, array $off = []): GroupModelParameters
    {
        $count = $set->count();
        $parameters = new GroupModelParameters(GroupPredictionModel::MODEL_VERSION, $tau, $off, [], self::DEFAULT_KAPPA_KNOWN, self::DEFAULT_KAPPA_UNKNOWN, null, self::MEMBER_KAPPA, [], [], [], log(TimePredictionCalculator::DEFAULT_IMPROVEMENT_RATIO), [], [], [], []);
        $inFit = [];

        for ($i = 0; $i < $count; $i++) {
            $inFit[$i] = $set->isPredictable($i) && $set->solved[$i] < $cut;
        }

        // 1. The coordination constants and the reference's
        $coordination = [];
        $baseline = [];

        foreach ($inFit as $i => $fitted) {
            if (!$fitted) {
                continue;
            }

            [$logDifficulty] = self::logDifficulty($set, $i, $tau);
            $y = log($set->seconds[$i]);
            $size = GroupCoordination::sizeGroup($set->members[$i]);
            $coordination[self::coordinationKey($size, $set->first[$i], $set->unknown[$i])][] = $y - $logDifficulty - $this->pacePart($set, $i, $parameters);

            if (self::hasBaseline($set, $i)) {
                $baseline[$size . '|' . (int) $set->first[$i]][] = $y - log((float) $set->score[$i]) + log($set->rateWithoutDrift[$i]);
            }
        }

        $parameters = self::with($parameters, coordination: self::medians($coordination, self::MINIMUM_BUCKET), baselineCoordination: self::medians($baseline, self::MINIMUM_BUCKET));
        $residuals = self::residualsOf($set, $this->bases($set, $parameters));

        // 2. κ on the fit window's last 91 days (first tries), the synergy with it
        $validation = [];

        foreach ($inFit as $i => $fitted) {
            if ($fitted && $set->first[$i] && $set->solved[$i] >= $cut - self::VALIDATION_DAYS * self::DAY) {
                $validation[] = $i;
            }
        }

        $halfLife = $parameters->isOn(GroupModelParameters::PART_DECAY) ? self::HALF_LIFE_DAYS : null;
        $parameters = self::with($parameters, halfLifeDays: $halfLife);
        $kappa = [self::DEFAULT_KAPPA_KNOWN, self::DEFAULT_KAPPA_UNKNOWN];
        $validationError = null;

        if ($parameters->isOn(GroupModelParameters::PART_SYNERGY) && $validation !== []) {
            // κ known moves only the synergy of pairs/teams without unknown members, κ unknown only the others' (the
            // members' offsets do not depend on either): two one-dimensional grids find the two-dimensional optimum
            $errors = [];

            foreach ([0 => self::KAPPA_KNOWN_GRID, 1 => self::KAPPA_UNKNOWN_GRID] as $which => $grid) {
                $best = null;

                foreach ($grid as $candidate) {
                    $tried = $which === 0 ? [$candidate, $kappa[1]] : [$kappa[0], $candidate];
                    [$synergy] = $this->synergy($set, $residuals, self::with($parameters, kappaKnown: $tried[0], kappaUnknown: $tried[1]));
                    $error = 0.0;

                    foreach ($validation as $i) {
                        if (($set->unknown[$i] === 0) === ($which === 0)) {
                            $error += abs((float) $residuals[$i] - $synergy[$i]);
                        }
                    }

                    if ($best === null || $error < $best - 1e-12) {
                        $best = $error;
                        $kappa = $tried;
                    }
                }

                $errors[] = $best;
            }

            $validationError = array_sum($errors) / count($validation);
        }

        $parameters = self::with($parameters, kappaKnown: $kappa[0], kappaUnknown: $kappa[1]);
        [$synergy, $experience] = $this->synergy($set, $residuals, $parameters);

        // 3. Centre offsets: experience, then the difficulty's source
        $byExperience = [];

        foreach ($inFit as $i => $fitted) {
            if ($fitted) {
                $byExperience[self::experienceBucket($experience[$i])][] = (float) $residuals[$i] - $synergy[$i];
            }
        }

        $experienceOffsets = $parameters->isOn(GroupModelParameters::PART_OFFSETS) ? self::shrunkMedians($byExperience) : [];
        $bySource = [];

        foreach ($inFit as $i => $fitted) {
            if ($fitted) {
                $bySource[self::logDifficulty($set, $i, $tau)[1]][] = (float) $residuals[$i] - $synergy[$i] - ($experienceOffsets[self::experienceBucket($experience[$i])] ?? 0.0);
            }
        }

        $sourceOffsets = $parameters->isOn(GroupModelParameters::PART_OFFSETS) ? self::shrunkMedians($bySource) : [];
        $byEvent = [];

        foreach ($inFit as $i => $fitted) {
            if ($fitted) {
                $byEvent[self::eventKey($set->competition[$i])][] = (float) $residuals[$i] - $synergy[$i]
                    - ($experienceOffsets[self::experienceBucket($experience[$i])] ?? 0.0)
                    - ($sourceOffsets[self::logDifficulty($set, $i, $tau)[1]] ?? 0.0);
            }
        }

        $eventOffsets = $parameters->isOn(GroupModelParameters::PART_EVENT) ? self::shrunkMedians($byEvent) : [];

        // 4. A repeat after the pair/team's own time on the puzzle
        $ratios = [];

        foreach ($this->ownEarlier($set, $parameters) as $i => $earlier) {
            if ($earlier !== null && $inFit[$i]) {
                $ratios[] = log($set->seconds[$i]) - $earlier;
            }
        }

        $parameters = self::with(
            $parameters,
            experienceOffsets: $experienceOffsets,
            sourceOffsets: $sourceOffsets,
            eventOffsets: $eventOffsets,
            repeatLogRatio: $ratios !== [] ? self::median($ratios) : log(TimePredictionCalculator::DEFAULT_IMPROVEMENT_RATIO),
        );

        // 5. The windows on the fit window's residuals (the centre without a window first)
        $replay = $this->replay($set, $parameters, withWindows: false);
        $parameters = $this->fitWindows($set, $replay, $inFit, $parameters);

        $fitRows = count(array_filter($inFit));

        return self::with($parameters, summary: [
            'fit_rows' => $fitRows,
            'fit_first_tries' => count(array_filter($inFit, static fn (bool $fitted, int $i): bool => $fitted && $set->first[$i], ARRAY_FILTER_USE_BOTH)),
            'validation_rows' => count($validation),
            'validation_error' => $validationError !== null ? round($validationError, 5) : '-',
            'kappa_known' => $kappa[0],
            'kappa_unknown' => $kappa[1],
            'repeat_ratio' => round(exp($parameters->repeatLogRatio), 4),
            'repeat_ratio_samples' => count($ratios),
            'coordination_buckets' => count($parameters->coordination),
        ]);
    }

    /**
     * Every result predicted as of its moment with the given fit.
     */
    public function replay(GroupResultSet $set, GroupModelParameters $parameters, bool $withWindows = true): GroupReplay
    {
        $replay = new GroupReplay();
        $bases = $this->bases($set, $parameters);
        $residuals = self::residualsOf($set, $bases);
        [$synergy, $experience] = $this->synergy($set, $residuals, $parameters);
        $ownEarlier = $this->ownEarlier($set, $parameters);
        $count = $set->count();

        for ($i = 0; $i < $count; $i++) {
            $replay->experience[$i] = $experience[$i];
            $replay->baselineCentre[$i] = null;
            $replay->baselineWindow[$i] = null;

            if (!$set->isPredictable($i)) {
                $replay->centre[$i] = null;
                $replay->window[$i] = null;
                $replay->kind[$i] = null;
                $replay->logDifficulty[$i] = null;
                $replay->source[$i] = null;
                $replay->base[$i] = null;
                $replay->synergy[$i] = null;
                $replay->bucket[$i] = null;

                continue;
            }

            [$logDifficulty, $source] = self::logDifficulty($set, $i, $parameters->tau);
            $base = (float) $bases[$i];
            $earlier = $ownEarlier[$i];

            if ($earlier !== null) {
                $kind = GroupReplay::KIND_OWN;
                $centre = $earlier + $parameters->repeatLogRatio;
            } else {
                $kind = $set->first[$i] ? GroupReplay::KIND_FIRST : GroupReplay::KIND_REPEAT;
                $centre = $base + $synergy[$i]
                    + ($parameters->experienceOffsets[self::experienceBucket($experience[$i])] ?? 0.0)
                    + ($parameters->sourceOffsets[$source] ?? 0.0)
                    + ($parameters->eventOffsets[self::eventKey($set->competition[$i])] ?? 0.0);
            }

            $size = GroupCoordination::sizeGroup($set->members[$i]);
            $replay->centre[$i] = $centre;
            $replay->kind[$i] = $kind;
            $replay->logDifficulty[$i] = $logDifficulty;
            $replay->source[$i] = $source;
            $replay->base[$i] = $base;
            $replay->synergy[$i] = $synergy[$i];
            $replay->bucket[$i] = self::windowBucket($kind, $experience[$i], $set->unknown[$i], $source, $size);
            $replay->window[$i] = $withWindows ? self::window($centre, $parameters->quantiles[$kind] ?? $parameters->quantiles['all'] ?? null, self::scaleOf($parameters->scaleFactors, self::scaleLevels($kind, $source, $set->unknown[$i], $experience[$i], $size))) : null;

            if (self::hasBaseline($set, $i)) {
                $baselineCentre = ($parameters->baselineCoordination[$size . '|' . (int) $set->first[$i]] ?? log(GroupCoordination::seed($set->members[$i], $set->first[$i])))
                    + log((float) $set->score[$i]) - log($set->rateWithoutDrift[$i]);
                $replay->baselineCentre[$i] = $baselineCentre;
                $replay->baselineWindow[$i] = $withWindows ? self::window($baselineCentre, $parameters->baselineQuantiles[$size] ?? $parameters->baselineQuantiles['all'] ?? null, 1.0) : null;
            }
        }

        return $replay;
    }

    /**
     * The reference predicts only what GroupExpectation's rule can: every member known, a rated puzzle.
     */
    public static function hasBaseline(GroupResultSet $set, int $i): bool
    {
        return $set->isPredictable($i) && $set->unknown[$i] === 0 && $set->score[$i] !== null && $set->score[$i] > 0 && $set->rateWithoutDrift[$i] > 0;
    }

    /**
     * The coordination rule with the model's buckets, c + ln D + ln 1/R, of the predictable results (null for the
     * others) - nothing of the result's own time.
     *
     * @return list<null|float>
     */
    private function bases(GroupResultSet $set, GroupModelParameters $parameters): array
    {
        $bases = [];
        $count = $set->count();

        for ($i = 0; $i < $count; $i++) {
            if (!$set->isPredictable($i)) {
                $bases[] = null;

                continue;
            }

            [$logDifficulty] = self::logDifficulty($set, $i, $parameters->tau);
            $size = GroupCoordination::sizeGroup($set->members[$i]);
            $coordination = $parameters->coordination[self::coordinationKey($size, $set->first[$i], $set->unknown[$i])]
                ?? $parameters->coordination[self::coordinationKey($size, $set->first[$i], 0)]
                ?? log(GroupCoordination::seed($set->members[$i], $set->first[$i]));
            $bases[] = $coordination + $logDifficulty + $this->pacePart($set, $i, $parameters);
        }

        return $bases;
    }

    /**
     * ln t - the rule's centre
     *
     * @param list<null|float> $bases
     * @return list<null|float>
     */
    private static function residualsOf(GroupResultSet $set, array $bases): array
    {
        $residuals = [];

        foreach ($bases as $i => $base) {
            $residuals[] = $base !== null ? log($set->seconds[$i]) - $base : null;
        }

        return $residuals;
    }

    /**
     * ln 1 / R, the unknown members counted with the known ones' mean rate.
     */
    private function pacePart(GroupResultSet $set, int $i, GroupModelParameters $parameters): float
    {
        $rate = $parameters->isOn(GroupModelParameters::PART_DRIFT) ? $set->rate[$i] : $set->rateWithoutDrift[$i];
        $known = $set->members[$i] - $set->unknown[$i];

        return -log($rate * $set->members[$i] / $known);
    }

    /**
     * Every predictable result's synergy as of it (its pair/team's earlier residuals, fading, shrunk toward the members'
     * offsets) and how many results of the pair/team came before it. In the order of the set (= of the solves).
     *
     * @param list<null|float> $residuals
     * @return array{0: list<float>, 1: list<int>}
     */
    private function synergy(GroupResultSet $set, array $residuals, GroupModelParameters $parameters): array
    {
        /** @var array<int, array{0: float, 1: float, 2: float, 3: int}> $teams weight sum, weighted residual sum, last solve, results */
        $teams = [];
        /** @var array<int, array{0: int, 1: float, 2: int, 3: float}> $members results and residual sum - of all, of those with every member known */
        $members = [];
        $synergy = [];
        $experience = [];
        $useSynergy = $parameters->isOn(GroupModelParameters::PART_SYNERGY);
        $useMembers = $parameters->isOn(GroupModelParameters::PART_MEMBER);
        $halfLife = $parameters->halfLifeDays;
        $count = $set->count();

        for ($i = 0; $i < $count; $i++) {
            $residual = $residuals[$i];
            $team = $set->team[$i];

            if ($residual === null) {
                $synergy[] = 0.0;
                $experience[] = $teams[$team][3] ?? 0;

                continue;
            }

            $players = $set->registeredMembers($i);
            $known = $set->unknown[$i] === 0;
            $prior = 0.0;

            // Like with like: a pair/team whose every member is known starts from its members' offsets in other such
            // pairs/teams; one with an unknown member from their offsets in all of theirs - the unknown people they
            // bring (often the same family) are part of those
            if ($useMembers && $players !== []) {
                foreach ($players as $player) {
                    $state = $members[$player] ?? [0, 0.0, 0, 0.0];
                    $prior += $known ? $state[3] / ($state[2] + $parameters->memberKappa) : $state[1] / ($state[0] + $parameters->memberKappa);
                }

                $prior /= count($players);
            }

            [$weights, $sum, $last, $results] = $teams[$team] ?? [0.0, 0.0, $set->solved[$i], 0];

            if ($halfLife !== null && $results > 0) {
                $fade = 0.5 ** (($set->solved[$i] - $last) / self::DAY / $halfLife);
                $weights *= $fade;
                $sum *= $fade;
            }

            $kappa = $known ? $parameters->kappaKnown : $parameters->kappaUnknown;
            $synergy[] = $useSynergy ? ($sum + $kappa * $prior) / ($weights + $kappa) : 0.0;
            $experience[] = $results;
            $teams[$team] = [$weights + 1.0, $sum + $residual, $set->solved[$i], $results + 1];

            foreach ($players as $player) {
                $state = $members[$player] ?? [0, 0.0, 0, 0.0];
                $members[$player] = [$state[0] + 1, $state[1] + $residual, $state[2] + ($known ? 1 : 0), $state[3] + ($known ? $residual : 0.0)];
            }
        }

        return [$synergy, $experience];
    }

    /**
     * Per result: the log seconds of the same pair/team's latest earlier predictable result on the puzzle when the
     * result is a repeat (null otherwise, or with the part off).
     *
     * @return list<null|float>
     */
    private function ownEarlier(GroupResultSet $set, GroupModelParameters $parameters): array
    {
        $count = $set->count();

        if (!$parameters->isOn(GroupModelParameters::PART_OWN_REPEAT)) {
            return array_fill(0, $count, null);
        }

        $earlier = [];
        $last = [];

        for ($i = 0; $i < $count; $i++) {
            $key = $set->team[$i] . '|' . $set->puzzle[$i];
            $earlier[] = $set->first[$i] === false && isset($last[$key]) ? $last[$key] : null;

            if ($set->isPredictable($i)) {
                $last[$key] = log($set->seconds[$i]);
            }
        }

        return $earlier;
    }

    /**
     * @param array<int, bool> $inFit
     */
    private function fitWindows(GroupResultSet $set, GroupReplay $replay, array $inFit, GroupModelParameters $parameters): GroupModelParameters
    {
        $rows = [];

        foreach ($inFit as $i => $fitted) {
            $centre = $replay->centre[$i];
            $kind = $replay->kind[$i];
            $source = $replay->source[$i];

            if ($fitted && $centre !== null && $kind !== null && $source !== null) {
                $rows[] = [
                    'error' => log($set->seconds[$i]) - $centre,
                    'levels' => self::scaleLevels($kind, $source, $set->unknown[$i], $replay->experience[$i], GroupCoordination::sizeGroup($set->members[$i])),
                    'kind' => $kind,
                ];
            }
        }

        // Raking: each factor's level scale = median |error| ÷ the rest of the scale (the kind's absolute, the others
        // relative to the overall median), a few rounds
        $factors = [];

        for ($round = 0; $round < self::SCALE_ROUNDS; $round++) {
            for ($factor = 0; $factor < self::SCALE_FACTORS; $factor++) {
                $byLevel = [];
                $all = [];

                foreach ($rows as $row) {
                    $level = $row['levels'][$factor];
                    $scaled = abs($row['error']) / self::scaleOf($factors, $row['levels']);
                    $byLevel[$level][] = $scaled;
                    $all[] = $scaled;
                }

                if ($all === []) {
                    continue;
                }

                $overall = $factor === 0 ? 1.0 : self::median($all);

                foreach ($byLevel as $level => $values) {
                    if (count($values) >= self::MINIMUM_SCALE_LEVEL && $overall > 0) {
                        $factors[$level] = ($factors[$level] ?? 1.0) * self::median($values) / $overall;
                    }
                }
            }
        }

        $byKind = [];

        foreach ($rows as $row) {
            $scaled = $row['error'] / self::scaleOf($factors, $row['levels']);
            $byKind[$row['kind']][] = $scaled;
            $byKind['all'][] = $scaled;
        }

        $quantiles = [];

        foreach ($byKind as $kind => $values) {
            if (count($values) >= self::MINIMUM_QUANTILE_ROWS) {
                $quantiles[$kind] = self::quantiles($values);
            }
        }

        // The reference's window: quantiles of its residual per size
        $bySize = [];

        foreach ($inFit as $i => $fitted) {
            $baselineCentre = $replay->baselineCentre[$i];

            if ($fitted && $baselineCentre !== null) {
                $error = log($set->seconds[$i]) - $baselineCentre;
                $bySize[GroupCoordination::sizeGroup($set->members[$i])][] = $error;
                $bySize['all'][] = $error;
            }
        }

        $baselineQuantiles = [];

        foreach ($bySize as $size => $values) {
            if (count($values) >= self::MINIMUM_QUANTILE_ROWS) {
                $baselineQuantiles[$size] = self::quantiles($values);
            }
        }

        return self::with($parameters, scaleFactors: array_map(static fn (float $factor): float => round($factor, 6), $factors), quantiles: $quantiles, baselineQuantiles: $baselineQuantiles);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string} the result's level of each window factor (SCALE_FACTORS)
     */
    private static function scaleLevels(string $kind, string $source, int $unknown, int $experience, int $size): array
    {
        return [
            'kind:' . $kind,
            // A puzzle without any difficulty (no index, no estimate - ~1.5 % of first tries, too few for a level of
            // their own) is scaled like one known from its picture alone, the nearest kind of uncertainty
            'source:' . ($source === 'none' ? 'image' : $source),
            'unknown:' . min($unknown, 2),
            'experience:' . self::experienceBucket($experience),
            'size:' . $size,
        ];
    }

    /**
     * @param array<int|string, float> $factors
     * @param array<int, string> $levels
     */
    private static function scaleOf(array $factors, array $levels): float
    {
        $scale = 1.0;

        foreach ($levels as $level) {
            $scale *= $factors[$level] ?? 1.0;
        }

        return $scale;
    }

    /**
     * @param null|list<float> $quantiles
     * @return null|array{0: float, 1: float, 2: float, 3: float}
     */
    private static function window(float $centre, null|array $quantiles, float $scale): null|array
    {
        if ($quantiles === null || count($quantiles) !== 4) {
            return null;
        }

        return [$centre + $quantiles[0] * $scale, $centre + $quantiles[1] * $scale, $centre + $quantiles[2] * $scale, $centre + $quantiles[3] * $scale];
    }

    private static function eventKey(bool $event): string
    {
        return $event ? 'event' : 'elsewhere';
    }

    private static function coordinationKey(int $size, bool $first, int $unknown): string
    {
        return sprintf('%d|%d|%d', $size, (int) $first, min($unknown, 2));
    }

    /**
     * @param array<int|string, list<float>> $groups
     * @return array<int|string, float>
     */
    private static function medians(array $groups, int $minimum): array
    {
        $medians = [];

        foreach ($groups as $key => $values) {
            if ($values !== [] && count($values) >= $minimum) {
                $medians[$key] = round(self::median($values), 6);
            }
        }

        ksort($medians);

        return $medians;
    }

    /**
     * @param array<int|string, list<float>> $groups
     * @return array<int|string, float>
     */
    private static function shrunkMedians(array $groups): array
    {
        $offsets = [];

        foreach ($groups as $key => $values) {
            if ($values === []) {
                continue;
            }

            $offsets[$key] = round(self::median($values) * count($values) / (count($values) + self::OFFSET_SHRINK), 6);
        }

        ksort($offsets);

        return $offsets;
    }

    /**
     * percentile_cont(0.5); 0 for no values
     *
     * @param list<float> $values
     */
    public static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2.0;
    }

    /**
     * Linear interpolation between the closest ranks (percentile_cont) at QUANTILES
     *
     * @param list<float> $values
     * @return list<float>
     */
    public static function quantiles(array $values): array
    {
        if ($values === []) {
            return [];
        }

        sort($values);
        $last = count($values) - 1;
        $quantiles = [];

        foreach (self::QUANTILES as $quantile) {
            $position = $quantile * $last;
            $low = (int) floor($position);
            $high = min($low + 1, $last);
            $quantiles[] = round($values[$low] + ($values[$high] - $values[$low]) * ($position - $low), 6);
        }

        return $quantiles;
    }

    /**
     * @param null|array<int|string, float> $coordination
     * @param null|array<int|string, float> $baselineCoordination
     * @param null|array<int|string, float> $experienceOffsets
     * @param null|array<int|string, float> $sourceOffsets
     * @param null|array<int|string, float> $eventOffsets
     * @param null|array<int|string, float> $scaleFactors
     * @param null|array<int|string, list<float>> $quantiles
     * @param null|array<int|string, list<float>> $baselineQuantiles
     * @param null|array<string, int|float|string> $summary
     */
    private static function with(
        GroupModelParameters $parameters,
        null|array $coordination = null,
        null|array $baselineCoordination = null,
        null|float $kappaKnown = null,
        null|float $kappaUnknown = null,
        null|float $halfLifeDays = null,
        null|array $experienceOffsets = null,
        null|array $sourceOffsets = null,
        null|array $eventOffsets = null,
        null|float $repeatLogRatio = null,
        null|array $scaleFactors = null,
        null|array $quantiles = null,
        null|array $baselineQuantiles = null,
        null|array $summary = null,
    ): GroupModelParameters {
        return new GroupModelParameters(
            modelVersion: $parameters->modelVersion,
            tau: $parameters->tau,
            off: $parameters->off,
            coordination: $coordination ?? $parameters->coordination,
            kappaKnown: $kappaKnown ?? $parameters->kappaKnown,
            kappaUnknown: $kappaUnknown ?? $parameters->kappaUnknown,
            halfLifeDays: $halfLifeDays ?? $parameters->halfLifeDays,
            memberKappa: $parameters->memberKappa,
            experienceOffsets: $experienceOffsets ?? $parameters->experienceOffsets,
            sourceOffsets: $sourceOffsets ?? $parameters->sourceOffsets,
            eventOffsets: $eventOffsets ?? $parameters->eventOffsets,
            repeatLogRatio: $repeatLogRatio ?? $parameters->repeatLogRatio,
            scaleFactors: $scaleFactors ?? $parameters->scaleFactors,
            quantiles: $quantiles ?? $parameters->quantiles,
            baselineCoordination: $baselineCoordination ?? $parameters->baselineCoordination,
            baselineQuantiles: $baselineQuantiles ?? $parameters->baselineQuantiles,
            summary: $summary ?? $parameters->summary,
        );
    }
}
