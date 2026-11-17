<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A standing and the slugs of its event (GetPublicStandingHead) - an edition lives under its series' URL.
 */
readonly final class PublicStandingHead
{
    public function __construct(
        public CompetitionStandingOverview $standing,
        public null|string $eventSlug,
        public null|string $seriesSlug,
    ) {
    }
}
