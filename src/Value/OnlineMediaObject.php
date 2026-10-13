<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * An object as an evidence store holds it (OnlineMediaStorage): its key, size, content type (when the store says) and
 * when it was last written.
 */
final readonly class OnlineMediaObject
{
    public function __construct(
        public string $key,
        public int $bytes,
        public null|string $contentType,
        public null|DateTimeImmutable $lastModified,
    ) {
    }
}
