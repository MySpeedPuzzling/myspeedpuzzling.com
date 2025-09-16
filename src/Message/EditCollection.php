<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\CollectionVisibility;

readonly final class EditCollection
{
    public function __construct(
        public string $collectionId,
        public string $playerId,
        public string $name,
        public null|string $description,
        public CollectionVisibility $visibility,
    ) {
    }
}
