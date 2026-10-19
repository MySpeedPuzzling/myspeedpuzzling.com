<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\ImageEstimates;

use SpeedPuzzling\Web\Query\GetLatestImageEstimateEvaluation;
use SpeedPuzzling\Web\Results\ImageEstimateEvaluationResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\ImageKind;
use SpeedPuzzling\Web\Value\PiecesGroup;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Whether the picture-based estimates may be shown for a size group × image kind (Insights plan §5.4; Jan: "switched
 * on once the out-of-sample gates pass", picture-only estimates show only where the gate passes, even after the
 * INSIGHTS_PUBLIC flag is gone). Reads the stored gate record (ImageEstimateEvaluation, the latest run of
 * `myspeedpuzzling:evaluate-image-estimates`) and applies the thresholds on read. **Fails closed**: no record, a record
 * of another model version, no numbers for the group, or a group with fewer than MIN_MEASURED_PUZZLES measured puzzles
 * = no.
 *
 * The gates (on newer puzzles, k = 0..4 - docs/features/insights/image-estimate-gates.md):
 * - Spearman of difficulty ≥ 0.6;
 * - tier within ±1 ≥ 80 % and exact tier ≥ max(46 %, always "Average" on the same puzzles) + 10 points;
 * - the 50 % and 80 % windows' hit rate within ± 3 points of 50 % / 80 %;
 * - at least 50 measured puzzles in the group.
 *
 * "Clearly above" = 10 points: at the minimum of 50 puzzles one standard error of a share near 50 % is 7 points
 * (√(0.25 ÷ 50)), so 10 points is ~1.4 of them there and ~3 at 250 puzzles - smaller gains are within the noise of
 * the groups that just pass the size rule; the image pilot cleared it on a random set (60 % vs 46 %,
 * prediction-research.md §2 Q3).
 *
 * The record is read once per request (ResetInterface - FrankenPHP worker mode).
 */
final class ImageEstimateGate implements ResetInterface
{
    public const int MIN_MEASURED_PUZZLES = 50;
    public const float MIN_SPEARMAN = 0.6;
    public const float MIN_TIER_WITHIN_ONE = 0.80;
    public const float ALWAYS_AVERAGE = 0.46;
    public const float EXACT_TIER_MARGIN = 0.10;
    public const float WINDOW50_TARGET = 0.50;
    public const float WINDOW80_TARGET = 0.80;
    public const float WINDOW_TOLERANCE = 0.03;

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

    public function passesFor(PiecesGroup $sizeGroup, ImageKind $imageKind): bool
    {
        return $this->failuresFor($sizeGroup, $imageKind) === [];
    }

    /**
     * Why the gate is closed for the group (empty = open).
     *
     * @return list<string>
     */
    public function failuresFor(PiecesGroup $sizeGroup, ImageKind $imageKind): array
    {
        if ($this->loaded === false) {
            $this->record = $this->getLatestImageEstimateEvaluation->latest();
            $this->loaded = true;
        }

        if ($this->record === null) {
            return ['no gate record yet (myspeedpuzzling:evaluate-image-estimates)'];
        }

        if ($this->record->modelVersion !== TimePredictionCalculator::MODEL_VERSION) {
            return [sprintf('the gate record measured model %d, the current model is %d', $this->record->modelVersion, TimePredictionCalculator::MODEL_VERSION)];
        }

        $group = $this->record->group($sizeGroup->value, $imageKind->value);

        if ($group === null) {
            return ['no evaluated predictions in this group'];
        }

        return self::failures($group);
    }

    /**
     * The gates on one group's numbers (ImageEstimateEvaluator) - a missing number fails.
     *
     * @param array<string, mixed> $metrics
     * @return list<string>
     */
    public static function failures(array $metrics): array
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

        $withinOne = self::number($metrics['tierWithinOne'] ?? null);

        if ($withinOne === null || $withinOne < self::MIN_TIER_WITHIN_ONE) {
            $failures[] = sprintf('tier within one %s < %.2f', self::show($withinOne), self::MIN_TIER_WITHIN_ONE);
        }

        $exact = self::number($metrics['tierExact'] ?? null);
        $exactNeeded = max(self::ALWAYS_AVERAGE, self::number($metrics['alwaysAverage'] ?? null) ?? self::ALWAYS_AVERAGE) + self::EXACT_TIER_MARGIN;

        // A hair of tolerance: 0.46 + 0.10 is 0.56000000000000005 in floating point
        if ($exact === null || $exact < $exactNeeded - 1e-9) {
            $failures[] = sprintf('exact tier %s < %.2f (always "Average" + %.2f)', self::show($exact), $exactNeeded, self::EXACT_TIER_MARGIN);
        }

        foreach ([['window50Hit', self::WINDOW50_TARGET], ['window80Hit', self::WINDOW80_TARGET]] as [$key, $target]) {
            $hit = self::number($metrics[$key] ?? null);

            if ($hit === null || abs($hit - $target) > self::WINDOW_TOLERANCE + 1e-9) {
                $failures[] = sprintf('%s %s not within %.2f ± %.2f', $key, self::show($hit), $target, self::WINDOW_TOLERANCE);
            }
        }

        return $failures;
    }

    private static function number(mixed $value): null|float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    private static function show(null|float $value): string
    {
        return $value !== null ? sprintf('%.3f', $value) : 'n/a';
    }
}
