<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ImageKind;

/**
 * A puzzle's picture-based difficulty estimate (PuzzleDifficultyEstimate) as GetPuzzleDifficultyEstimates reads it -
 * always of the puzzle's current picture, never of a secret puzzle. Log space: exp(logDifficulty) is the difficulty
 * index (1.0 = average), logP10 / logP90 its 80 % interval; imageKind what the same picture shows (its `artwork`
 * analysis - unknown without one).
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
        public ImageKind $imageKind = ImageKind::Unknown,
        // Since difficulty_prior 1.2.0 (PuzzleDifficultyEstimate) - null for older pushes; nothing shown reads them yet
        public null|float $logP25 = null,
        public null|float $logP75 = null,
        public null|float $pixelsPerPiece = null,
        public null|float $cropTrust = null,
        /** @var null|list<float> */
        public null|array $pictureFactors = null,
        public null|string $pictureFactorsVersion = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row puzzle_id, model, model_version, log_difficulty, log_p10, log_p90, out_of_fold,
     *                                  training_size, image_fingerprint, computed_at, received_at, artwork_kind,
     *                                  log_p25, log_p75, pixels_per_piece, crop_trust, picture_factors (JSON),
     *                                  picture_factors_version
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
            imageKind: ImageKind::fromArtworkKind($row['artwork_kind'] ?? null),
            logP25: self::number($row['log_p25'] ?? null),
            logP75: self::number($row['log_p75'] ?? null),
            pixelsPerPiece: self::number($row['pixels_per_piece'] ?? null),
            cropTrust: self::number($row['crop_trust'] ?? null),
            pictureFactors: self::factors($row['picture_factors'] ?? null),
            pictureFactorsVersion: is_string($row['picture_factors_version'] ?? null) ? $row['picture_factors_version'] : null,
        );
    }

    private static function number(mixed $value): null|float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return null|list<float>
     */
    private static function factors(mixed $json): null|array
    {
        $values = is_string($json) ? json_decode($json, true) : null;

        if (is_array($values) === false || array_is_list($values) === false) {
            return null;
        }

        $factors = [];

        foreach ($values as $value) {
            if (is_numeric($value) === false) {
                return null;
            }

            $factors[] = (float) $value;
        }

        return $factors;
    }
}
