<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

/**
 * The parent series of an edition, as carried by a competition detail. Standalone competitions
 * have no series (the detail's `series` is null).
 */
final class CompetitionSeriesSummaryResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public null|string $slug,
    ) {
    }
}
