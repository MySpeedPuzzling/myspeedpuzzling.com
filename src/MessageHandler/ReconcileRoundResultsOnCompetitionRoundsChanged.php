<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Events\CompetitionRoundsChanged;
use SpeedPuzzling\Web\Services\SeriesEditions\SeriesEditionReconciler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The competition's round results - for an edition its series' picks first, then the round results of the series'
 * editions (docs/features/events-page/high-frequency-series.md).
 */
#[AsMessageHandler]
readonly final class ReconcileRoundResultsOnCompetitionRoundsChanged
{
    public function __construct(
        private SeriesEditionReconciler $reconciler,
    ) {
    }

    public function __invoke(CompetitionRoundsChanged $event): void
    {
        $this->reconciler->reconcileCompetition($event->competitionId);
    }
}
