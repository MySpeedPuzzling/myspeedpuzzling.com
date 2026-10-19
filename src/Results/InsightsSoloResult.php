<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Value\InsightsImageTrait;
use SpeedPuzzling\Web\Value\InsightsPuzzlePersonality;
use SpeedPuzzling\Web\Value\TimePredictionMethod;

/**
 * One solo result of the Insights page (GetInsightsSoloResults): the result, its puzzle's statistics and difficulty,
 * the prediction stored for it, and whether the puzzle is on the player's shelf. Only results that count anywhere:
 * timed (> 0 s), not suspicious, on a puzzle that is not secret. Definitions: docs/features/insights/computation.md
 * "Core sections" §2.
 */
readonly final class InsightsSoloResult
{
    /** A puzzle's median solo time is a yardstick from this many solo times on (the profile card's rule) */
    public const int MIN_PUZZLE_SOLO_TIMES = 5;

    /**
     * @param int $attempt 1-based number of the result among the player's results on the puzzle (solve moment, then logging moment)
     * @param null|DateTimeImmutable $finishedAt the solve date the player gave (stored without a time), null = logged as solved then
     * @param list<string> $imageTagKeys the picture's tags of the image-traits group (puzzle_tag)
     */
    public function __construct(
        public string $timeId,
        public string $puzzleId,
        public int $seconds,
        public DateTimeImmutable $solvedAt,
        public DateTimeImmutable $trackedAt,
        public null|DateTimeImmutable $finishedAt,
        public bool $firstAttempt,
        public bool $unboxed,
        public int $attempt,
        public bool $atEvent,
        public bool $onShelf,
        public int $piecesCount,
        public null|string $manufacturerId,
        public null|string $manufacturerName,
        public null|int $puzzleMedianSeconds,
        public int $puzzleSoloTimesCount,
        public null|float $difficultyScore,
        public null|int $difficultyTier,
        public null|string $difficultyConfidence,
        public int $difficultySampleSize,
        public null|float $sumLogIndices,
        public null|float $memorabilityScore,
        public null|float $skillSensitivityScore,
        public null|float $predictabilityScore,
        public null|float $boxDependenceScore,
        public null|float $improvementCeilingScore,
        public null|bool $predictable,
        public null|TimePredictionMethod $predictionMethod,
        public null|int $predictedSeconds,
        public null|int $predictedRangeLowSeconds,
        public null|int $predictedRangeHighSeconds,
        public null|int $predictedAttemptNumber,
        public null|int $predictionModelVersion,
        public array $imageTagKeys,
    ) {
    }

    /**
     * Time ÷ the puzzle's median solo time; null while the median rests on fewer than MIN_PUZZLE_SOLO_TIMES times.
     */
    public function ratio(): null|float
    {
        if ($this->puzzleMedianSeconds === null || $this->puzzleMedianSeconds <= 0 || $this->puzzleSoloTimesCount < self::MIN_PUZZLE_SOLO_TIMES) {
            return null;
        }

        return $this->seconds / $this->puzzleMedianSeconds;
    }

    public function hasPrediction(): bool
    {
        return $this->predictable === true && $this->predictedSeconds !== null && $this->predictedSeconds > 0;
    }

    /**
     * @return list<InsightsPuzzlePersonality>
     */
    public function personalities(): array
    {
        return InsightsPuzzlePersonality::of($this->skillSensitivityScore, $this->boxDependenceScore, $this->memorabilityScore);
    }

    /**
     * @return list<InsightsImageTrait>
     */
    public function imageTraits(): array
    {
        return InsightsImageTrait::fromTagKeys($this->imageTagKeys);
    }

    /**
     * @param array{
     *     time_id: string,
     *     puzzle_id: string,
     *     seconds_to_solve: int|string,
     *     solved_at: string,
     *     tracked_at: string,
     *     finished_at: null|string,
     *     first_attempt: bool|int|string,
     *     unboxed: bool|int|string,
     *     attempt: int|string,
     *     at_event: bool|int|string,
     *     on_shelf: bool|int|string,
     *     pieces_count: int|string,
     *     manufacturer_id: null|string,
     *     manufacturer_name: null|string,
     *     median_time_solo: null|int|string,
     *     solved_times_solo_count: null|int|string,
     *     difficulty_score: null|float|string,
     *     difficulty_tier: null|int|string,
     *     confidence: null|string,
     *     sample_size: null|int|string,
     *     sum_log_indices: null|float|string,
     *     memorability_score: null|float|string,
     *     skill_sensitivity_score: null|float|string,
     *     predictability_score: null|float|string,
     *     box_dependence_score: null|float|string,
     *     improvement_ceiling_score: null|float|string,
     *     predictable: null|bool|int|string,
     *     prediction_method: null|string,
     *     predicted_seconds: null|int|string,
     *     predicted_range_low_seconds: null|int|string,
     *     predicted_range_high_seconds: null|int|string,
     *     predicted_attempt_number: null|int|string,
     *     prediction_model_version: null|int|string,
     *     image_tag_keys: null|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $tagKeys = $row['image_tag_keys'] !== null ? json_decode($row['image_tag_keys'], true) : [];
        // Stored timestamps are UTC (timestamp without time zone)
        $utc = new DateTimeZone('UTC');

        return new self(
            timeId: $row['time_id'],
            puzzleId: $row['puzzle_id'],
            seconds: (int) $row['seconds_to_solve'],
            solvedAt: new DateTimeImmutable($row['solved_at'], $utc),
            trackedAt: new DateTimeImmutable($row['tracked_at'], $utc),
            finishedAt: $row['finished_at'] !== null ? new DateTimeImmutable($row['finished_at'], $utc) : null,
            firstAttempt: self::bool($row['first_attempt']),
            unboxed: self::bool($row['unboxed']),
            attempt: (int) $row['attempt'],
            atEvent: self::bool($row['at_event']),
            onShelf: self::bool($row['on_shelf']),
            piecesCount: (int) $row['pieces_count'],
            manufacturerId: $row['manufacturer_id'],
            manufacturerName: $row['manufacturer_name'],
            puzzleMedianSeconds: self::intOrNull($row['median_time_solo']),
            puzzleSoloTimesCount: (int) ($row['solved_times_solo_count'] ?? 0),
            difficultyScore: self::floatOrNull($row['difficulty_score']),
            difficultyTier: self::intOrNull($row['difficulty_tier']),
            difficultyConfidence: $row['confidence'],
            difficultySampleSize: (int) ($row['sample_size'] ?? 0),
            sumLogIndices: self::floatOrNull($row['sum_log_indices']),
            memorabilityScore: self::floatOrNull($row['memorability_score']),
            skillSensitivityScore: self::floatOrNull($row['skill_sensitivity_score']),
            predictabilityScore: self::floatOrNull($row['predictability_score']),
            boxDependenceScore: self::floatOrNull($row['box_dependence_score']),
            improvementCeilingScore: self::floatOrNull($row['improvement_ceiling_score']),
            predictable: $row['predictable'] !== null ? self::bool($row['predictable']) : null,
            predictionMethod: $row['prediction_method'] !== null ? TimePredictionMethod::tryFrom($row['prediction_method']) : null,
            predictedSeconds: self::intOrNull($row['predicted_seconds']),
            predictedRangeLowSeconds: self::intOrNull($row['predicted_range_low_seconds']),
            predictedRangeHighSeconds: self::intOrNull($row['predicted_range_high_seconds']),
            predictedAttemptNumber: self::intOrNull($row['predicted_attempt_number']),
            predictionModelVersion: self::intOrNull($row['prediction_model_version']),
            imageTagKeys: is_array($tagKeys) ? array_values(array_filter($tagKeys, is_string(...))) : [],
        );
    }

    private static function bool(bool|int|string $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    private static function intOrNull(null|int|string $value): null|int
    {
        return $value !== null ? (int) $value : null;
    }

    private static function floatOrNull(null|float|string $value): null|float
    {
        return $value !== null ? (float) $value : null;
    }
}
