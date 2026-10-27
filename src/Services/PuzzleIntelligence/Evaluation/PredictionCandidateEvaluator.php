<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation;

use SpeedPuzzling\Web\Value\PredictionPairRow;
use SpeedPuzzling\Web\Value\PredictorKind;

/**
 * Gate v2 for a candidate time model (docs/features/insights/image-estimate-gates.md "Candidate time models"): a
 * prediction stored side by side under its own predictor / model version (solving_time_prediction - e.g. the
 * statistical predictor of a next MODEL_VERSION before its launch) judged the same way as the picture estimates -
 * per size group: the windows' honesty out of period (OutOfPeriodCalibration, with the hysteresis carried in
 * $openBefore) and the skill against a reference (the shown model) per k bucket (PairedSkill), plus the live vs
 * reconstructed slices. Pure, no database. The verdict: failures().
 */
final class PredictionCandidateEvaluator
{
    /** A candidate's rows are not split by what the picture shows */
    public const string ANY_KIND = 'any';

    /** Paired solves a skill bucket needs to be judged */
    public const int MIN_SKILL_ROWS = 30;

    /** z of a one-sided 95 % bound: a bucket is "measurably worse" when its excess error minus this × SE is still > the margin */
    public const float ONE_SIDED_95_Z = 1.6448536269514722;

    /**
     * How much worse (mean |ln error|) a k bucket may be before it counts - the time model review's acceptance rule
     * ("no group worse by > 0.002", 2026-10-26 §3): with 10k+ solves per bucket a 0.0003 difference is significant
     * and still nothing a member would notice (measured: blend@3 vs statistical@3, 1000 pieces, k = 10-19)
     */
    public const float NON_INFERIORITY_MARGIN = 0.002;

    /**
     * The record subject of a candidate against its reference, e.g. "statistical@4 vs statistical@3"
     */
    public static function subject(PredictorKind $candidate, int $candidateVersion, PredictorKind $reference, int $referenceVersion): string
    {
        return sprintf('%s@%d vs %s@%d', $candidate->value, $candidateVersion, $reference->value, $referenceVersion);
    }

    /**
     * @param iterable<PredictionPairRow> $rows
     * @param array<string, list<string>> $openBefore the groups ("<size group>|any") whose honesty the previous record opened
     * @param null|float $splitAt the out-of-period split moment (epoch seconds); null = the median solve moment
     * @return array{groups: list<array<string, mixed>>, overall: array<string, mixed>, splitAt: null|float}
     */
    public static function evaluate(iterable $rows, array $openBefore = [], null|float $splitAt = null): array
    {
        /** @var array<string, PredictionGroupAccumulator> $groups */
        $groups = [];
        /** @var array<string, int> $counts */
        $counts = [];
        $overall = new PredictionGroupAccumulator();
        $all = 0;

        foreach ($rows as $row) {
            $key = $row->sizeGroup->value . '|' . self::ANY_KIND;
            $slices = [$row->live ? 'live' : 'reconstructed'];

            ($groups[$key] ??= new PredictionGroupAccumulator())->add($row->kIndices, $row->solvedAt, $row->actualSeconds, $row->candidate, $row->candidate, $row->reference, $slices);
            $overall->add($row->kIndices, $row->solvedAt, $row->actualSeconds, $row->candidate, $row->candidate, $row->reference, $slices);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $all++;
        }

        $splitAt ??= OutOfPeriodCalibration::medianMoment($overall->calibration->solvedAt());
        ksort($groups);
        $metrics = [];

        foreach ($groups as $key => $accumulator) {
            [$sizeGroup, $kind] = explode('|', $key);
            $group = ['sizeGroup' => $sizeGroup, 'imageKind' => $kind, 'rows' => $counts[$key]] + $accumulator->metrics($splitAt);
            $group['calibration']['previouslyOpen'] = isset($openBefore[$key]);
            $metrics[] = $group;
        }

        return [
            'groups' => $metrics,
            'overall' => ['rows' => $all] + $overall->metrics($splitAt),
            'splitAt' => $splitAt,
        ];
    }

    /**
     * The verdict on one group's numbers - a missing number fails:
     * 1. honesty out of period (OutOfPeriodCalibration::failures(), hysteresis from the record);
     * 2. skill: no k bucket of at least MIN_SKILL_ROWS paired solves measurably worse than the reference by more than
     *    NON_INFERIORITY_MARGIN (one-sided 95 %), and the candidate's error over all paired solves no larger than the
     *    reference's.
     *
     * @param array<string, mixed> $metrics
     * @return list<string>
     */
    public static function failures(array $metrics): array
    {
        $calibration = $metrics['calibration'] ?? null;
        /** @var array<string, mixed> $calibrationNumbers */
        $calibrationNumbers = is_array($calibration) ? $calibration : [];
        $failures = is_array($calibration)
            ? OutOfPeriodCalibration::failures($calibrationNumbers, ($calibrationNumbers['previouslyOpen'] ?? false) === true)
            : ['no out-of-period window numbers'];

        $skill = is_array($metrics['skill'] ?? null) ? $metrics['skill'] : [];
        $rows = 0.0;
        $candidateError = 0.0;
        $referenceError = 0.0;

        foreach ($skill as $bucket) {
            if (!is_array($bucket)) {
                continue;
            }

            $bucketRows = self::number($bucket['rows'] ?? null) ?? 0.0;
            $candidate = self::number($bucket['maeCandidate'] ?? null);
            $reference = self::number($bucket['maeReference'] ?? null);

            if ($candidate !== null && $reference !== null) {
                $rows += $bucketRows;
                $candidateError += $bucketRows * $candidate;
                $referenceError += $bucketRows * $reference;
            }

            if ($bucketRows < self::MIN_SKILL_ROWS) {
                continue;
            }

            $difference = self::number($bucket['meanDifference'] ?? null);
            $se = self::number($bucket['differenceSe'] ?? null);

            if ($difference === null || $se === null || $difference - self::ONE_SIDED_95_Z * $se > self::NON_INFERIORITY_MARGIN + 1e-9) {
                $failures[] = sprintf('k = %s: error above the reference by %s (SE %s)', is_string($bucket['k'] ?? null) ? $bucket['k'] : '?', self::show($difference), self::show($se));
            }
        }

        if ($rows < self::MIN_SKILL_ROWS) {
            $failures[] = sprintf('%d paired solves, at least %d needed', (int) $rows, self::MIN_SKILL_ROWS);
        } elseif ($candidateError > $referenceError + 1e-9) {
            $failures[] = sprintf('error %s above the reference\'s %s over all paired solves', self::show($candidateError / $rows), self::show($referenceError / $rows));
        }

        return $failures;
    }

    /**
     * The groups whose honesty a stored record's numbers opened - the next record's hysteresis
     *
     * @param list<array<string, mixed>> $groups
     * @return array<string, list<string>>
     */
    public static function honestyOpenGroups(array $groups): array
    {
        $open = [];

        foreach ($groups as $group) {
            $calibration = $group['calibration'] ?? null;

            if (!is_array($calibration) || !is_string($group['sizeGroup'] ?? null) || !is_string($group['imageKind'] ?? null)) {
                continue;
            }

            /** @var array<string, mixed> $calibration */
            if (OutOfPeriodCalibration::failures($calibration, ($calibration['previouslyOpen'] ?? false) === true) === []) {
                $open[$group['sizeGroup'] . '|' . $group['imageKind']] = [];
            }
        }

        return $open;
    }

    private static function number(mixed $value): null|float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    private static function show(null|float $value): string
    {
        return $value !== null ? sprintf('%.4f', $value) : 'n/a';
    }
}
