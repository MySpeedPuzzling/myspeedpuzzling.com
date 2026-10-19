<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A puzzle's picture-based difficulty estimate as a prediction uses it (PuzzleDifficultyEstimate, Insights plan §5.4):
 * the log difficulty (0.0 = the average puzzle), its 80 % interval, which model made it and what the picture shows.
 * Read only for the puzzle's current picture and never for a secret puzzle (StatisticalInputsSql::imageEstimateJoins()).
 */
readonly final class ImagePriorInput
{
    /** The standard normal's 90 % quantile - the half-width of an 80 % interval in standard deviations */
    private const float Z90 = 1.2815515655446004;

    public function __construct(
        public float $logDifficulty,
        public float $logP10,
        public float $logP90,
        public string $model,
        public ImageKind $kind,
        // The estimate's model version (the derive's MODEL_VERSION) - which estimate generation a prediction rested on
        public null|string $modelVersion = null,
    ) {
    }

    /**
     * The estimate's own uncertainty as a variance in log units: its 80 % interval read as ± 1.28 sigma. The pipeline
     * builds the interval from out-of-fold residuals of well measured puzzles per size group × image kind, so it is
     * the error of the estimate itself - what replaces the fitted puzzle term b ÷ (k + 1) in the window of a prediction
     * resting on the picture alone.
     */
    public function variance(): float
    {
        return (max(0.0, $this->logP90 - $this->logP10) / (2 * self::Z90)) ** 2;
    }

    /**
     * @param array<string, mixed> $row image_log, image_log_p10, image_log_p90, image_model, image_model_version, image_artwork_kind
     */
    public static function fromRow(array $row): null|self
    {
        $log = $row['image_log'] ?? null;
        $p10 = $row['image_log_p10'] ?? null;
        $p90 = $row['image_log_p90'] ?? null;
        $model = $row['image_model'] ?? null;

        if (!is_numeric($log) || !is_numeric($p10) || !is_numeric($p90) || !is_string($model)) {
            return null;
        }

        $version = $row['image_model_version'] ?? null;

        return new self((float) $log, (float) $p10, (float) $p90, $model, ImageKind::fromArtworkKind($row['image_artwork_kind'] ?? null), is_string($version) ? $version : null);
    }
}
