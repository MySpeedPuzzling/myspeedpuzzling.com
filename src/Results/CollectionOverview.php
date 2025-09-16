<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CollectionVisibility;

readonly final class CollectionOverview
{
    public function __construct(
        public string $playerId,
        public null|string $collectionId,
        public string $name,
        public null|string $description,
        public CollectionVisibility $visibility,
    ) {
    }
}
