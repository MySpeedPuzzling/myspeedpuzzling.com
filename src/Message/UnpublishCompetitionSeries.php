<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Back to draft - only while none of its editions has participants, results or linked solving times
 * (UnpublishBlockers::forSeries(), docs/features/organizations/README.md "Drafts", P8).
 */
readonly final class UnpublishCompetitionSeries
{
    public function __construct(
        public string $seriesId,
    ) {
    }
}
