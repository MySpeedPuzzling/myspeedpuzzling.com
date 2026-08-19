<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

/**
 * One collection card of the puzzle library summary: the collection as
 * GET /me/collections lists it (same fields, same order) plus the number of
 * puzzles in it, which the library page prints on every card. The system
 * collection is "default" (its visibility is the owner's puzzle-collection
 * setting), custom collections carry their own id and visibility.
 */
final class LibraryCollectionResponse
{
    public function __construct(
        public string $collection_id,
        public string $name,
        public null|string $description,
        public string $visibility,
        public int $item_count,
    ) {
    }
}
