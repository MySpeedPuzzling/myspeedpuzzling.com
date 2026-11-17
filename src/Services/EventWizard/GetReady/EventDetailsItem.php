<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard\GetReady;

use SpeedPuzzling\Web\Results\GetReadyFacts;
use SpeedPuzzling\Web\Results\GetReadyRow;

/**
 * ✓ Event details - always done: the event exists with its name, place and date.
 */
readonly final class EventDetailsItem implements GetReadyItem
{
    public function order(): int
    {
        return 10;
    }

    public function row(GetReadyFacts $facts): GetReadyRow
    {
        return new GetReadyRow(key: 'details', labelKey: 'event_wizard.get_ready.details', done: true);
    }
}
