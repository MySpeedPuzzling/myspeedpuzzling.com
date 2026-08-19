<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

final class CollectionResponse
{
    public function __construct(
        public string $collectionId,
        public string $name,
        public null|string $description,
        public string $visibility,
    ) {
    }
}
