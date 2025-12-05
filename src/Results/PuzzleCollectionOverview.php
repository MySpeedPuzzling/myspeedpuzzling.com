<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CollectionVisibility;

readonly final class PuzzleCollectionOverview
{
    public function __construct(
        public string $collectionItemId,
        public null|string $collectionId,
        public string $collectionName,
        public CollectionVisibility $visibility,
    ) {
    }
}
