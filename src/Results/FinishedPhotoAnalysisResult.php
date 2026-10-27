<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ImageAnalyzer;

/**
 * A stored analysis of a finished-puzzle photo as the internal API reads it back (GetFinishedPhotoAnalyses): the
 * puzzle, the photo's fingerprint, the catalogue picture it was matched against, the payload as pushed.
 */
readonly final class FinishedPhotoAnalysisResult
{
    /**
     * @param array<string, mixed> $result
     */
    public function __construct(
        public string $puzzleId,
        public ImageAnalyzer $analyzer,
        public string $analyzerVersion,
        public string $photoFingerprint,
        public string $imageFingerprint,
        public array $result,
        public null|DateTimeImmutable $analyzedAt,
        public DateTimeImmutable $receivedAt,
        public bool $current,
    ) {
    }
}
