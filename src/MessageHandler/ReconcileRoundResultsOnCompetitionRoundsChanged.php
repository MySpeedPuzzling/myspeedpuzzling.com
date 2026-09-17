<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Events\CompetitionRoundsChanged;
use SpeedPuzzling\Web\Services\RoundResults\RoundResultsReconciler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ReconcileRoundResultsOnCompetitionRoundsChanged
{
    public function __construct(
        private RoundResultsReconciler $reconciler,
    ) {
    }

    public function __invoke(CompetitionRoundsChanged $event): void
    {
        $this->reconciler->reconcile($event->competitionId);
    }
}
