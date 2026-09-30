<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A photo kept for the player while their form comes back with an error (PhotoStash).
 */
readonly final class StashedPhoto
{
    public function __construct(
        public string $token,
        public string $fileName,
        public bool $hasPreview,
    ) {
    }
}
