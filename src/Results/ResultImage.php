<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * A rendered result share image. `withPlaceholder` = drawn over the placeholder photo
 * because the real one is missing: not cached anywhere, so the next request tries again.
 * `drawnAt` = when the card served was drawn (kept since then); null for a placeholder card.
 */
readonly final class ResultImage
{
    public function __construct(
        public string $content,
        public bool $withPlaceholder,
        public null|DateTimeImmutable $drawnAt,
    ) {
    }
}
