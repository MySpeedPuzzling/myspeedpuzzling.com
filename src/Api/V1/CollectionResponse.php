<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

final class CollectionResponse
{
    public function __construct(
        public string $collection_id,
        public string $name,
        public null|string $description,
        public string $visibility,
    ) {
    }
}
