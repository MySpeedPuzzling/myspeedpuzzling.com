<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\UploadedImageMetadata;

/**
 * A puzzle with a shown image, as the image analysis pipeline lists it (GetPuzzleImagesForAnalysis): what to download
 * (`image`, the stored path) and which picture it is (`imageFingerprint`), plus what is stored for it and who is working on it, per analyzer.
 */
readonly final class PuzzleImageForAnalysis
{
    /**
     * @param array<string, array{analyzerVersion: string, imageFingerprint: string, analyzedAt: null|DateTimeImmutable, receivedAt: DateTimeImmutable}> $analyses keyed by analyzer
     * @param array<string, array{worker: string, expiresAt: DateTimeImmutable}> $claims the claims held now, keyed by analyzer
     * @param int $solvedCount every counted result of the puzzle (puzzle_statistics.solved_times_count: solo, pairs and
     *   teams, suspicious left out) - the pipeline works on the most-solved puzzles first where order matters
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
        public int $solvedCount,
        public array $analyses,
        public array $claims = [],
        // What the picture's file carried when it was uploaded (docs/features/upload-image-metadata.md) - null = not known
        public null|UploadedImageMetadata $uploadMetadata = null,
    ) {
    }
}
