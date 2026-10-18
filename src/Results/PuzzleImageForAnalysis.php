<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * A puzzle with a shown image, as the image analysis pipeline lists it (GetPuzzleImagesForAnalysis): what to download
 * (`image`, the stored path) and which picture it is (`imageFingerprint`), plus what is stored for it per analyzer.
 */
readonly final class PuzzleImageForAnalysis
{
    /**
     * @param array<string, array{analyzerVersion: string, imageFingerprint: string, analyzedAt: null|DateTimeImmutable, receivedAt: DateTimeImmutable}> $analyses keyed by analyzer
     */
    public function __construct(
        public string $puzzleId,
        public string $name,
        public int $piecesCount,
        public null|string $manufacturerId,
        public null|string $manufacturerName,
        public string $image,
        public string $imageFingerprint,
        public null|float $imageRatio,
        public array $analyses,
    ) {
    }
}
