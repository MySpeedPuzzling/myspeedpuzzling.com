<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\ImageEstimates;

use SpeedPuzzling\Web\Query\GetLatestImageEstimateEvaluation;
use SpeedPuzzling\Web\Results\ImageEstimateEvaluationResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation\OutOfPeriodCalibration;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation\PredictionGroupAccumulator;
use SpeedPuzzling\Web\Value\WindowRefit;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\ImageKind;
use SpeedPuzzling\Web\Value\PiecesGroup;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Whether the picture-based estimates may be shown for a size group × image kind (Insights plan §5.4; Jan: "switched
 * on once the out-of-sample gates pass", picture-only estimates show only where the gate passes, even after the
 * INSIGHTS_PUBLIC flag is gone). Reads the stored gate record (ImageEstimateEvaluation, the latest run of
 * `myspeedpuzzling:evaluate-image-estimates`) and applies the thresholds on read. **Fails closed**: no record, a record
 * of the first gate rules (gate v1), a record of another model version, no numbers for the group, an estimate
 * generation the record did not measure in the group = no.
 *
 * Gate v2 (docs/features/insights/image-estimate-gates.md "Gate v2" - the 2026-10-26 model review: exact tier is
 * capped by label noise and judged a 0.4-SE miss, the window bars were in-sample and nothing had hysteresis):
 * 1. **Honesty** - the picture-only windows out of period: scales fitted before the record's split moment, the later
 *    solves' 50 % / 80 % hits within ± 3 points by their Wilson interval (OutOfPeriodCalibration); a group open in the
 *    previous record for the same estimate generations stays open until a share leaves ± 5;
 * 2. **Skill** - at k = 0 the picture-only error ≤ K0_ERROR_RATIO × the brand × pieces prior's (the statistical
 *    predictor) on the same solves, with a narrower 80 % window at equal coverage; at k = 1-2 and 3-4 the blend never
 *    measurably worse than the statistical prediction;
 * 3. **Ranking** - Spearman ≥ 0.6 on ≥ 50 measured puzzles.
 * The tier numbers (exact tier vs its label-noise ceiling, tier range coverage, ranked probability skill) are in the
 * record for people, never a gate.
 *
 * The record is read once per request (ResetInterface - FrankenPHP worker mode).
 */
final class ImageEstimateGate implements ResetInterface
{
    public const int MIN_MEASURED_PUZZLES = 50;
    public const float MIN_SPEARMAN = 0.6;

    /** The picture-only error at k = 0 against the brand × pieces prior's on the same solves */
    public const float K0_ERROR_RATIO = 0.90;

    /** Paired solves a skill bucket needs to be judged */
    public const int MIN_SKILL_ROWS = 30;

    /** z of a one-sided 95 % bound: the blend is "measurably worse" when its excess error minus this × SE is still > 0 */
    public const float ONE_SIDED_95_Z = 1.6448536269514722;

    private bool $loaded = false;

    private null|ImageEstimateEvaluationResult $record = null;

    public function __construct(
        private readonly GetLatestImageEstimateEvaluation $getLatestImageEstimateEvaluation,
    ) {
    }

    public function reset(): void
    {
        $this->loaded = false;
        $this->record = null;
    }

    /**
     * @param string $estimateGeneration the shown estimate's generation (ImagePriorInput::generation()):
     *                                   a record vouches only for the estimates it measured - after a derive model change
     *                                   the new estimates stay closed until a record measured them
     */
    public function passesFor(PiecesGroup $sizeGroup, ImageKind $imageKind, string $estimateGeneration): bool
    {
        return $this->failuresFor($sizeGroup, $imageKind, $estimateGeneration) === [];
    }

    /**
     * Why the gate is closed for the group and estimate generation (empty = open).
     *
     * @return list<string>
     */
    public function failuresFor(PiecesGroup $sizeGroup, ImageKind $imageKind, string $estimateGeneration): array
    {
        if ($this->loaded === false) {
            $this->record = $this->getLatestImageEstimateEvaluation->latest();
            $this->loaded = true;
        }

        if ($this->record === null) {
            return ['no gate record yet (myspeedpuzzling:evaluate-image-estimates)'];
        }

        if ($this->record->gateVersion < ImageEstimateEvaluator::GATE_VERSION) {
            return [sprintf('the gate record was made for gate v%d, the gate is v%d (myspeedpuzzling:evaluate-image-estimates)', $this->record->gateVersion, ImageEstimateEvaluator::GATE_VERSION)];
        }

        if ($this->record->modelVersion !== TimePredictionCalculator::MODEL_VERSION) {
            return [sprintf('the gate record measured model %d, the current model is %d', $this->record->modelVersion, TimePredictionCalculator::MODEL_VERSION)];
        }

        $group = $this->record->group($sizeGroup->value, $imageKind->value);

        if ($group === null) {
            return ['no evaluated predictions in this group'];
        }

        $measured = $group['estimateModels'] ?? [];

        $rows = is_array($measured) ? ($measured[$estimateGeneration] ?? 0) : 0;

        if (!is_int($rows) || $rows <= 0) {
            return [sprintf('the gate record did not measure the estimates of "%s" in this group', $estimateGeneration)];
        }

        return self::failures($group);
    }

    /**
     * The gates on one group's numbers (ImageEstimateEvaluator, gate v2) - a missing number fails.
     *
     * @param array<string, mixed> $metrics
     * @return list<string>
     */
    public static function failures(array $metrics): array
    {
        return [...self::rankingFailures($metrics), ...self::honestyFailures($metrics), ...self::skillFailures($metrics)];
    }

    /**
     * Rule 3: Spearman ≥ 0.6 on ≥ 50 measured puzzles.
     *
     * @param array<string, mixed> $metrics
     * @return list<string>
     */
    public static function rankingFailures(array $metrics): array
    {
        $failures = [];
        $measured = self::number($metrics['measuredPuzzles'] ?? null) ?? 0.0;

        if ($measured < self::MIN_MEASURED_PUZZLES) {
            $failures[] = sprintf('%d measured puzzles, at least %d needed', (int) $measured, self::MIN_MEASURED_PUZZLES);
        }

        $spearman = self::number($metrics['spearman'] ?? null);

        if ($spearman === null || $spearman < self::MIN_SPEARMAN) {
            $failures[] = sprintf('Spearman %s < %.2f', self::show($spearman), self::MIN_SPEARMAN);
        }

        return $failures;
    }

    /**
     * Rule 1: the picture-only windows out of period, with the hysteresis the record carries.
     *
     * @param array<string, mixed> $metrics
     * @return list<string>
     */
    public static function honestyFailures(array $metrics): array
    {
        $calibration = $metrics['calibration'] ?? null;

        if (!is_array($calibration)) {
            return ['no out-of-period window numbers'];
        }

        /** @var array<string, mixed> $calibration */
        return OutOfPeriodCalibration::failures($calibration, ($calibration['previouslyOpen'] ?? false) === true);
    }

    /**
     * Rule 2: k = 0 the picture beats the brand × pieces prior by 10 % with a sharper window; k = 1-4 the blend is
     * never measurably worse than the statistical prediction (its mean excess error's one-sided 95 % bound ≤ 0, per
     * bucket of at least MIN_SKILL_ROWS solves; the two buckets together need that many).
     *
     * @param array<string, mixed> $metrics
     * @return list<string>
     */
    public static function skillFailures(array $metrics): array
    {
        $skill = $metrics['skill'] ?? null;
        $failures = [];

        $k0 = PredictionGroupAccumulator::skillBucket($skill, '0');
        $rows = self::number($k0['rows'] ?? null) ?? 0.0;
        $candidate = self::number($k0['maeCandidate'] ?? null);
        $reference = self::number($k0['maeReference'] ?? null);

        if ($rows < self::MIN_SKILL_ROWS) {
            $failures[] = sprintf('%d paired solves at k = 0, at least %d needed', (int) $rows, self::MIN_SKILL_ROWS);
        } elseif ($candidate === null || $reference === null || $candidate > self::K0_ERROR_RATIO * $reference + 1e-9) {
            $failures[] = sprintf('k = 0 error %s > %.2f × the brand prior\'s %s', self::show($candidate), self::K0_ERROR_RATIO, self::show($reference));
        }

        $width = self::number($k0['width80Candidate'] ?? null);
        $referenceWidth = self::number($k0['width80Reference'] ?? null);

        if ($rows >= self::MIN_SKILL_ROWS && ($width === null || $referenceWidth === null || $width >= $referenceWidth)) {
            $failures[] = sprintf('k = 0 80 %% window at equal coverage %s not narrower than the brand prior\'s %s', self::show($width), self::show($referenceWidth));
        }

        $judged = 0.0;

        foreach (['1-2', '3-4'] as $bucket) {
            $numbers = PredictionGroupAccumulator::skillBucket($skill, $bucket);
            $bucketRows = self::number($numbers['rows'] ?? null) ?? 0.0;
            $judged += $bucketRows;

            if ($bucketRows < self::MIN_SKILL_ROWS) {
                continue;
            }

            $difference = self::number($numbers['meanDifference'] ?? null);
            $se = self::number($numbers['differenceSe'] ?? null);

            if ($difference === null || $se === null || $difference - self::ONE_SIDED_95_Z * $se > 1e-9) {
                $failures[] = sprintf('k = %s blend error %s above the statistical by %s (SE %s)', $bucket, self::show(self::number($numbers['maeCandidate'] ?? null)), self::show($difference), self::show($se));
            }
        }

        if ($judged < self::MIN_SKILL_ROWS) {
            $failures[] = sprintf('%d paired solves at k = 1-4, at least %d needed', (int) $judged, self::MIN_SKILL_ROWS);
        }

        return $failures;
    }

    /**
     * The groups whose honesty a stored record opened (with its own hysteresis), and the estimate generations it
     * measured there - what the next record's hysteresis carries (ImageEstimateEvaluator::evaluate() $openBefore).
     * Only a gate v2 record of the current model version counts, and only groups its honesty test judged with today's
     * refit (WindowRefit::imageWindow() - a record of another refit earns the opening again).
     *
     * @return array<string, list<string>>
     */
    public static function honestyOpenGroups(null|ImageEstimateEvaluationResult $record): array
    {
        if ($record === null || $record->gateVersion < ImageEstimateEvaluator::GATE_VERSION || $record->modelVersion !== TimePredictionCalculator::MODEL_VERSION) {
            return [];
        }

        $open = [];

        foreach ($record->groups as $group) {
            $calibration = is_array($group['calibration'] ?? null) ? $group['calibration'] : [];

            if (!WindowRefit::imageWindow()->judged($calibration) || self::honestyFailures($group) !== [] || !is_string($group['sizeGroup'] ?? null) || !is_string($group['imageKind'] ?? null)) {
                continue;
            }

            $generations = is_array($group['estimateModels'] ?? null) ? array_map('strval', array_keys($group['estimateModels'])) : [];
            sort($generations);
            $open[$group['sizeGroup'] . '|' . $group['imageKind']] = $generations;
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
