<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard\GetReady;

use SpeedPuzzling\Web\Results\GetReadyFacts;
use SpeedPuzzling\Web\Results\GetReadyRow;

/**
 * Competition toolkit tip: "Try the timer screen on the hall's TV" - for whom the toolkit is on, once the event has a
 * timer screen (an OBS overlay does not count). Without a tick (v1 stores no "opened once").
 */
readonly final class TimerScreenTip implements GetReadyItem
{
    public function order(): int
    {
        return 70;
    }

    public function row(GetReadyFacts $facts): null|GetReadyRow
    {
        if ($facts->toolkitAvailable === false || $facts->screenDisplaysCount === 0) {
            return null;
        }

        return new GetReadyRow(
            key: 'timer_screen',
            labelKey: 'event_wizard.get_ready.timer_screen',
            done: null,
            route: 'competition_displays',
            routeParameters: ['competitionId' => $facts->competitionId],
            linkKey: 'event_wizard.get_ready.link.open',
        );
    }
}
