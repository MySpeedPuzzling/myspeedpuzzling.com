<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * A puzzle's picture-based difficulty estimate (PuzzleDifficultyEstimate) as GetPuzzleDifficultyEstimates reads it -
 * always of the puzzle's current picture, never of a secret puzzle. Log space: exp(logDifficulty) is the difficulty
 * index (1.0 = average), logP10 / logP90 its 80 % interval.
 */
readonly final class PuzzleDifficultyEstimateResult
{
    public function __construct(
        public string $puzzleId,
        public string $model,
        public string $modelVersion,
        public float $logDifficulty,
        public float $logP10,
        public float $logP90,
        public bool $outOfFold,
        public int $trainingSize,
        public string $imageFingerprint,
        public DateTimeImmutable $computedAt,
        public DateTimeImmutable $receivedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row puzzle_id, model, model_version, log_difficulty, log_p10, log_p90, out_of_fold,
     *                                  training_size, image_fingerprint, computed_at, received_at
     */
    public static function fromDatabaseRow(array $row): self
    {
        assert(is_string($row['puzzle_id']));
        assert(is_string($row['model']));
        assert(is_string($row['model_version']));
        assert(is_numeric($row['log_difficulty']));
        assert(is_numeric($row['log_p10']));
        assert(is_numeric($row['log_p90']));
        assert(is_bool($row['out_of_fold']));
        assert(is_int($row['training_size']));
        assert(is_string($row['image_fingerprint']));
        assert(is_string($row['computed_at']));
        assert(is_string($row['received_at']));

        return new self(
            puzzleId: $row['puzzle_id'],
            model: $row['model'],
            modelVersion: $row['model_version'],
            logDifficulty: (float) $row['log_difficulty'],
            logP10: (float) $row['log_p10'],
            logP90: (float) $row['log_p90'],
            outOfFold: $row['out_of_fold'],
            trainingSize: $row['training_size'],
            imageFingerprint: $row['image_fingerprint'],
            computedAt: new DateTimeImmutable($row['computed_at']),
            receivedAt: new DateTimeImmutable($row['received_at']),
        );
    }
}
