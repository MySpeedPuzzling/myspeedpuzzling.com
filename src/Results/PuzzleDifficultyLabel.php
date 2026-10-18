<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * A puzzle's measured difficulty as a training label for the image pipeline's difficulty model
 * (GetPuzzleDifficultyLabels) - aggregates of the puzzle only, nothing of any player.
 *
 * `logDifficulty` = ln of the puzzle's as-of difficulty (each index = a solver's first attempt ÷ that solver's pace
 * just before it, puzzle-intelligence README "Pace history"): the ln of the stored median index where the batch keeps
 * one (≥ 5 indices, `labelSource` "median"), else the mean of the ln indices (`labelSource` "mean_log", 1-4 indices -
 * for 1-2 it is the median of the ln indices anyway). `meanLogIndex` is sum_log_indices ÷ k for every puzzle.
 */
readonly final class PuzzleDifficultyLabel
{
    public const string SOURCE_MEDIAN = 'median';

    public const string SOURCE_MEAN_LOG = 'mean_log';

    public function __construct(
        public string $puzzleId,
        public float $logDifficulty,
        public string $labelSource,
        public float $meanLogIndex,
        public int $indexCount,
        public int $piecesCount,
        public null|string $manufacturerId,
        public null|DateTimeImmutable $addedAt,
        public null|string $imageFingerprint,
    ) {
    }

    /**
     * @param array<string, mixed> $row puzzle_id, sample_size, sum_log_indices, difficulty_score, pieces_count,
     *                                  manufacturer_id, added_at, image_fingerprint
     */
    public static function fromDatabaseRow(array $row): self
    {
        assert(is_string($row['puzzle_id']));
        assert(is_int($row['sample_size']) && $row['sample_size'] >= 1);
        assert(is_numeric($row['sum_log_indices']));
        assert(is_int($row['pieces_count']));

        $indexCount = $row['sample_size'];
        $meanLogIndex = (float) $row['sum_log_indices'] / $indexCount;
        $median = is_numeric($row['difficulty_score']) ? (float) $row['difficulty_score'] : null;

        return new self(
            puzzleId: $row['puzzle_id'],
            logDifficulty: round($median !== null && $median > 0.0 ? log($median) : $meanLogIndex, 6),
            labelSource: $median !== null && $median > 0.0 ? self::SOURCE_MEDIAN : self::SOURCE_MEAN_LOG,
            meanLogIndex: round($meanLogIndex, 6),
            indexCount: $indexCount,
            piecesCount: $row['pieces_count'],
            manufacturerId: is_string($row['manufacturer_id']) ? $row['manufacturer_id'] : null,
            addedAt: is_string($row['added_at']) ? new DateTimeImmutable($row['added_at']) : null,
            imageFingerprint: is_string($row['image_fingerprint']) ? $row['image_fingerprint'] : null,
        );
    }
}
