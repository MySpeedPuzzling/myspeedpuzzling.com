<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventWizard\GetReady;

use SpeedPuzzling\Web\Results\GetReadyFacts;
use SpeedPuzzling\Web\Results\GetReadyRow;

/**
 * Judges: "Judges added (n)" ✓ once a referee exists; before that a suggestion without a tick (a small event runs
 * without judges - never a task to tick off).
 */
readonly final class JudgesItem implements GetReadyItem
{
    public function order(): int
    {
        return 30;
    }

    public function row(GetReadyFacts $facts): GetReadyRow
    {
        if ($facts->refereesCount > 0) {
            return new GetReadyRow(
                key: 'judges',
                labelKey: 'event_wizard.get_ready.judges_added',
                done: true,
                labelParameters: ['%count%' => $facts->refereesCount],
                route: 'competition_referees',
                routeParameters: ['competitionId' => $facts->competitionId],
                linkKey: 'event_wizard.get_ready.link.manage',
            );
        }

        return new GetReadyRow(
            key: 'judges',
            labelKey: 'event_wizard.get_ready.judges_suggestion',
            done: null,
            route: 'competition_referees',
            routeParameters: ['competitionId' => $facts->competitionId],
            linkKey: 'event_wizard.get_ready.link.add_judges',
        );
    }
}
