<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\MetricConfidence;

readonly final class PuzzleDifficultyResult
{
    public function __construct(
        public string $puzzleId,
        public null|float $difficultyScore,
        public null|DifficultyTier $difficultyTier,
        public MetricConfidence $confidence,
        public int $sampleSize,
        public null|float $memorabilityScore,
        public null|float $skillSensitivityScore,
        public null|float $predictabilityScore,
        public null|float $boxDependenceScore,
    ) {
    }

    /**
     * @param array{
     *     puzzle_id: string,
     *     difficulty_score: null|float|string,
     *     difficulty_tier: null|int|string,
     *     confidence: string,
     *     sample_size: int|string,
     *     memorability_score: null|float|string,
     *     skill_sensitivity_score: null|float|string,
     *     predictability_score: null|float|string,
     *     box_dependence_score: null|float|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $tierValue = $row['difficulty_tier'] !== null ? (int) $row['difficulty_tier'] : null;

        return new self(
            puzzleId: $row['puzzle_id'],
            difficultyScore: $row['difficulty_score'] !== null ? (float) $row['difficulty_score'] : null,
            difficultyTier: $tierValue !== null ? DifficultyTier::from($tierValue) : null,
            confidence: MetricConfidence::from($row['confidence']),
            sampleSize: (int) $row['sample_size'],
            memorabilityScore: $row['memorability_score'] !== null ? (float) $row['memorability_score'] : null,
            skillSensitivityScore: $row['skill_sensitivity_score'] !== null ? (float) $row['skill_sensitivity_score'] : null,
            predictabilityScore: $row['predictability_score'] !== null ? (float) $row['predictability_score'] : null,
            boxDependenceScore: $row['box_dependence_score'] !== null ? (float) $row['box_dependence_score'] : null,
        );
    }
}
