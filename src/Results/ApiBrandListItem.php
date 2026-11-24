<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A brand as API v1 lists it (GET /api/v1/brands, GetApiBrandList) - its id is what GET /api/v1/puzzles filters by.
 */
readonly final class ApiBrandListItem
{
    public function __construct(
        public string $id,
        public string $name,
        public null|string $slug,
        // Storage path of the logo, the provider turns it into a URL
        public null|string $logo,
        // Exactly the "total" of GET /api/v1/puzzles?brand=<id>
        public int $puzzlesCount,
    ) {
    }
}
