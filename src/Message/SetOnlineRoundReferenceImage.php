<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The organiser's reference image of an online round for the completeness check (docs/features/online-competitions/
 * README.md §5.8, OnlineReferenceImages): stored as a JPEG under a new key, replacing the one before (deleted after the
 * commit). The upload is checked by the form (OnlineReferenceImage::constraint()); a damaged picture is refused by the
 * handler (OnlineReferenceImageUnreadable) before anything is stored. The round must use online timing
 * (OnlineRoundNotFound otherwise).
 */
readonly final class SetOnlineRoundReferenceImage
{
    public function __construct(
        public string $roundId,
        public UploadedFile $image,
    ) {
    }
}
