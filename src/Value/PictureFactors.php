<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the candidate model reads of a puzzle's current picture - its taste factor (TasteModel): the picture factors (16
 * floats of a frozen PCA basis of the SigLIP 2 crop vector - puzzle_difficulty_estimate.picture_factors, unsupervised:
 * no solve went into them) with the basis version, and the `artwork` kind of the picture; its k = 0 window (v6): how
 * alike the pieces look (PictureLookalikes). Any may be missing.
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
        // v6: how alike the picture's pieces look (the candidate's k = 0 window, PictureDispersionModel)
        public null|PictureLookalikes $lookalikes = null,
    ) {
    }

    /**
     * From the stored columns (picture_factors jsonb text, picture_factors_version, the artwork result's kind) - null
     * when neither the factors nor a kind is known.
     */
    public static function fromColumns(mixed $factorsJson, mixed $version, mixed $artworkKind, null|PictureLookalikes $lookalikes = null): null|self
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

        if ($factors === null && $kind === ImageKind::Unknown && $lookalikes === null) {
            return null;
        }

        return new self($factors, $factors !== null && is_string($version) ? $version : null, $kind, $lookalikes);
    }
}
