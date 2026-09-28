<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A rendered result share image. `withPlaceholder` = drawn over the placeholder photo
 * because the real one is missing: not cached anywhere, so the next request tries again.
 */
readonly final class ResultImage
{
    public function __construct(
        public string $content,
        public bool $withPlaceholder,
    ) {
    }
}
