<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CollectionVisibility;

readonly final class CollectionOverviewWithCount
{
    public function __construct(
        public null|string $collectionId,
        public string $name,
        public null|string $description,
        public CollectionVisibility $visibility,
        public DateTimeImmutable $createdAt,
        public int $itemCount,
        public bool $isSystemCollection,
    ) {
    }
}
