<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One finished-puzzle photo on the image pipeline's work list (GetFinishedPhotosForAnalysis): known only by its
 * fingerprint - no player, no solving time - and what is stored for it by the asked analyzer (null = nothing).
 */
readonly final class FinishedPhotoForAnalysis
{
    /**
     * @param null|array{analyzerVersion: string, photoFingerprint: string, imageFingerprint: string, analyzedAt: null|DateTimeImmutable, receivedAt: DateTimeImmutable} $analysis
     */
    public function __construct(
        public string $photoFingerprint,
        public null|array $analysis,
    ) {
    }
}
