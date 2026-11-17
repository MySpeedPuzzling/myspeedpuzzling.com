<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard\GetReady;

use SpeedPuzzling\Web\Results\GetReadyFacts;
use SpeedPuzzling\Web\Results\GetReadyRow;

/**
 * Competition toolkit tip: "Test run: start the stopwatch without Reveal" - for whom the toolkit is on, when a round
 * has a secret puzzle (the reveal at the stopwatch start is ticked per start, never by default). Links the stopwatch
 * of the first such round. Without a tick.
 */
readonly final class StopwatchTestRunTip implements GetReadyItem
{
    public function order(): int
    {
        return 80;
    }

    public function row(GetReadyFacts $facts): null|GetReadyRow
    {
        if ($facts->toolkitAvailable === false) {
            return null;
        }

        foreach ($facts->rounds as $round) {
            if ($round->secretPuzzlesCount > 0) {
                return new GetReadyRow(
                    key: 'stopwatch_test',
                    labelKey: 'event_wizard.get_ready.stopwatch_test',
                    done: null,
                    route: 'manage_round_stopwatch',
                    routeParameters: ['roundId' => $round->roundId],
                    linkKey: 'event_wizard.get_ready.link.stopwatch',
                );
            }
        }

        return null;
    }
}
