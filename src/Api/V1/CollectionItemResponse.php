<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

final class CollectionItemResponse
{
    public function __construct(
        public string $collection_item_id,
        public string $puzzle_id,
        public string $puzzle_name,
        public null|string $manufacturer_name,
        public int $pieces_count,
        public null|string $image,
        public null|string $comment,
        public string $added_at,
    ) {
    }
}
