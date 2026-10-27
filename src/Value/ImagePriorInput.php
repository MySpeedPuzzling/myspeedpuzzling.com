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
    public const float Z90 = 1.2815515655446004;

    /** The standard normal's 75 % quantile - the half-width of a 50 % interval in standard deviations */
    public const float Z75 = 0.6744897501960817;

    public function __construct(
        public float $logDifficulty,
        public float $logP10,
        public float $logP90,
        public string $model,
        public ImageKind $kind,
        // The estimate's model version (the derive's MODEL_VERSION) - which estimate generation a prediction rested on
        public null|string $modelVersion = null,
        // Since difficulty_prior 1.2.0, null before: the pipeline's own 50 % interval (the picture-only window's 50 %
        // ends, quantileOffsets()), the side of one piece in pixels and the crop trust (carried for later models).
        public null|float $logP25 = null,
        public null|float $logP75 = null,
        public null|float $pixelsPerPiece = null,
        public null|float $cropTrust = null,
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
     * The estimate's own interval as offsets from its log difficulty at the 10 / 25 / 75 / 90 % quantiles - asymmetric
     * as the pipeline builds it (per-puzzle σ̂ × conformal quantiles per size group, estimates.md "Picture estimate
     * 1.2.0"). The 50 % ends are the pipeline's own 50 % interval (logP25 / logP75); an estimate from before 1.2.0
     * has none and takes its 80 % offsets × z75 ÷ z90 (a normal shape on each side). Lower offsets never above 0,
     * upper ones never below.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function quantileOffsets(): array
    {
        $lower80 = min(0.0, $this->logP10 - $this->logDifficulty);
        $upper80 = max(0.0, $this->logP90 - $this->logDifficulty);

        if ($this->logP25 === null || $this->logP75 === null) {
            return [$lower80, $lower80 * self::Z75 / self::Z90, $upper80 * self::Z75 / self::Z90, $upper80];
        }

        return [
            $lower80,
            min(0.0, $this->logP25 - $this->logDifficulty),
            max(0.0, $this->logP75 - $this->logDifficulty),
            $upper80,
        ];
    }

    /**
     * The estimate generation as the gate record keys it (`estimateModels`, ImageEstimateGate::passesFor())
     */
    public function generation(): string
    {
        return self::generationOf($this->model, $this->modelVersion);
    }

    /**
     * "<model> <model version>" - the one spelling of an estimate generation, also the evaluation's
     * (GetImageEstimateEvaluationRows)
     */
    public static function generationOf(string $model, null|string $modelVersion): string
    {
        return trim($model . ' ' . ($modelVersion ?? ''));
    }

    /**
     * @param array<string, mixed> $row image_log, image_log_p10, image_log_p90, image_model, image_model_version, image_artwork_kind
     *                                  (+ optional image_log_p25, image_log_p75, image_pixels_per_piece, image_crop_trust)
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
        $optional = static fn (string $key): null|float => is_numeric($row[$key] ?? null) ? (float) $row[$key] : null;

        return new self(
            (float) $log,
            (float) $p10,
            (float) $p90,
            $model,
            ImageKind::fromArtworkKind($row['image_artwork_kind'] ?? null),
            is_string($version) ? $version : null,
            $optional('image_log_p25'),
            $optional('image_log_p75'),
            $optional('image_pixels_per_piece'),
            $optional('image_crop_trust'),
        );
    }
}
