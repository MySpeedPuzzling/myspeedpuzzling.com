<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What the event creation wizard's series path (W3) derives its steps' "done" from (GetEventWizardSeries::state()) - the
 * draft series is its only state.
 */
readonly final class EventWizardSeriesState
{
    public function __construct(
        public string $seriesId,
        public bool $isDraft,
        public int $editionsCount,
        public null|string $addedByPlayerId = null,
    ) {
    }
}
