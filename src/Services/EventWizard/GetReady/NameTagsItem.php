<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard\GetReady;

use SpeedPuzzling\Web\Results\GetReadyFacts;
use SpeedPuzzling\Web\Results\GetReadyRow;

/**
 * Print name tags - once somebody is going or a round has entries; a tip without a tick (printing leaves no data).
 */
readonly final class NameTagsItem implements GetReadyItem
{
    public function order(): int
    {
        return 60;
    }

    public function row(GetReadyFacts $facts): null|GetReadyRow
    {
        if ($facts->participantsGoing === 0 && $facts->hasEntries() === false) {
            return null;
        }

        return new GetReadyRow(
            key: 'name_tags',
            labelKey: 'event_wizard.get_ready.name_tags',
            done: null,
            route: 'competition_name_tags',
            routeParameters: ['competitionId' => $facts->competitionId],
            linkKey: 'event_wizard.get_ready.link.print',
        );
    }
}
