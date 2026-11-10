<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * A result share image read from ResultImageCache, with the moment it was kept (drawn).
 */
readonly final class KeptResultImage
{
    public function __construct(
        public string $content,
        public DateTimeImmutable $keptAt,
    ) {
    }
}
