<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * A recorded run of the image pipeline's difficulty model (PuzzleDifficultyEstimateModel) with its evaluation.
 */
readonly final class PuzzleDifficultyEstimateModelResult
{
    /**
     * @param array<mixed> $evaluation
     */
    public function __construct(
        public string $id,
        public string $model,
        public string $modelVersion,
        public int $trainingSize,
        public float $holdoutSpearman,
        public array $evaluation,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $receivedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row id, model, model_version, training_size, holdout_spearman, evaluation (JSON),
     *                                  created_at, received_at
     */
    public static function fromDatabaseRow(array $row): self
    {
        assert(is_string($row['id']));
        assert(is_string($row['model']));
        assert(is_string($row['model_version']));
        assert(is_int($row['training_size']));
        assert(is_numeric($row['holdout_spearman']));
        assert(is_string($row['evaluation']));
        assert(is_string($row['created_at']));
        assert(is_string($row['received_at']));

        $evaluation = json_decode($row['evaluation'], true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($evaluation));

        return new self(
            id: $row['id'],
            model: $row['model'],
            modelVersion: $row['model_version'],
            trainingSize: $row['training_size'],
            holdoutSpearman: (float) $row['holdout_spearman'],
            evaluation: $evaluation,
            createdAt: new DateTimeImmutable($row['created_at']),
            receivedAt: new DateTimeImmutable($row['received_at']),
        );
    }
}
