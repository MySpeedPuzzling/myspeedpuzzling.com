<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard\GetReady;

use SpeedPuzzling\Web\Results\GetReadyFacts;
use SpeedPuzzling\Web\Results\GetReadyRow;

/**
 * Competition toolkit tip: "Add the stream overlay to OBS" - for whom the toolkit is on, once the event has a stream
 * overlay (the Extras step's "Overlay for a live stream", W6). Links the displays list, where its link is copied. Without
 * a tick (nothing in the data says it was added to OBS).
 */
readonly final class OverlayTip implements GetReadyItem
{
    public function order(): int
    {
        return 75;
    }

    public function row(GetReadyFacts $facts): null|GetReadyRow
    {
        if ($facts->toolkitAvailable === false || $facts->overlayDisplaysCount === 0) {
            return null;
        }

        return new GetReadyRow(
            key: 'overlay',
            labelKey: 'event_wizard.get_ready.overlay',
            done: null,
            route: 'competition_displays',
            routeParameters: ['competitionId' => $facts->competitionId],
            linkKey: 'event_wizard.get_ready.link.open',
        );
    }
}
