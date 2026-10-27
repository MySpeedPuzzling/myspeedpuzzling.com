<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the candidate model's taste factor (TasteModel) reads of a puzzle's current picture: its picture factors (16
 * floats of a frozen PCA basis of the SigLIP 2 crop vector - puzzle_difficulty_estimate.picture_factors, unsupervised:
 * no solve went into them) with the basis version, and the `artwork` kind of the picture. Either may be missing.
 */
readonly final class PictureFactors
{
    /**
     * @param null|list<float> $factors exactly PuzzleDifficultyEstimateInput::PICTURE_FACTORS numbers, or none
     */
    public function __construct(
        public null|array $factors,
        public null|string $version,
        public ImageKind $kind = ImageKind::Unknown,
    ) {
    }

    /**
     * From the stored columns (picture_factors jsonb text, picture_factors_version, the artwork result's kind) - null
     * when neither the factors nor a kind is known.
     */
    public static function fromColumns(mixed $factorsJson, mixed $version, mixed $artworkKind): null|self
    {
        $factors = null;

        if (is_string($factorsJson)) {
            $decoded = json_decode($factorsJson, true);

            if (is_array($decoded) && count($decoded) === PuzzleDifficultyEstimateInput::PICTURE_FACTORS) {
                $factors = [];

                foreach ($decoded as $value) {
                    if (!is_numeric($value)) {
                        $factors = null;

                        break;
                    }

                    $factors[] = (float) $value;
                }
            }
        }

        $kind = $artworkKind === null ? ImageKind::Unknown : ImageKind::fromArtworkKind($artworkKind);

        if ($factors === null && $kind === ImageKind::Unknown) {
            return null;
        }

        return new self($factors, $factors !== null && is_string($version) ? $version : null, $kind);
    }
}
