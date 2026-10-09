<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\ReconcileRoundResults;
use SpeedPuzzling\Web\Services\SeriesEditions\SeriesEditionReconciler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Every series' picks first (an automatic reveal by time records nothing - this upgrades a date match to a puzzle
 * match, docs/features/events-page/high-frequency-series.md), then every competition's round results.
 */
#[AsMessageHandler]
readonly final class ReconcileRoundResultsHandler
{
    public function __construct(
        private SeriesEditionReconciler $reconciler,
    ) {
    }

    /**
     * @return array{linked: int, moved: int, released: int, roundsLinked: int, roundsUnlinked: int}
     */
    public function __invoke(ReconcileRoundResults $message): array
    {
        return $this->reconciler->reconcile();
    }
}
