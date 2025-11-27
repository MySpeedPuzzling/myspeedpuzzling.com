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
        public bool $isUnsolvedPuzzles = false,
        public bool $isWishList = false,
        public bool $isSellSwapList = false,
        public bool $isLendBorrowList = false,
        public int $lentCount = 0,
        public int $borrowedCount = 0,
    ) {
    }

    public function isPublic(): bool
    {
        return $this->visibility === CollectionVisibility::Public;
    }
}
