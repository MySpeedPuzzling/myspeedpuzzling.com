<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Events\SeriesEditionsChanged;
use SpeedPuzzling\Web\Services\SeriesEditions\SeriesEditionReconciler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Routed sync: runs on postFlush, inside the transaction that changed the series' editions
 * (docs/features/events-page/high-frequency-series.md).
 */
#[AsMessageHandler]
readonly final class ReconcileSeriesEditionsOnSeriesEditionsChanged
{
    public function __construct(
        private SeriesEditionReconciler $reconciler,
    ) {
    }

    public function __invoke(SeriesEditionsChanged $event): void
    {
        $this->reconciler->reconcile($event->seriesId);
    }
}
