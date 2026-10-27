<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A puzzle on the finished-photo work list (GetFinishedPhotosForAnalysis): its catalogue picture (the stage finds the
 * assembled puzzle in a photo by matching that picture) and the photos to work on.
 */
readonly final class FinishedPhotosOfPuzzle
{
    /**
     * @param list<FinishedPhotoForAnalysis> $photos
     */
    public function __construct(
        public string $puzzleId,
        public int $piecesCount,
        public string $image,
        public string $imageFingerprint,
        public array $photos,
    ) {
    }
}
