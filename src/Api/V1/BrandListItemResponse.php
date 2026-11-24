<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

final class BrandListItemResponse
{
    public function __construct(
        // The id to send as brand (or manufacturer) to GET /api/v1/puzzles - the same as manufacturer.id on a puzzle card
        public string $id,
        public string $name,
        // The brand's page on the website is /en/puzzle/brand/{slug} (null for a brand without one yet)
        public null|string $slug,
        // Absolute URL of the brand's logo image
        public null|string $logoUrl,
        // Number of puzzles of the brand in the catalog - the "total" of GET /api/v1/puzzles?brand={id}
        public int $puzzlesCount,
    ) {
    }
}
