<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Events\PuzzleMergeReverted;
use SpeedPuzzling\Web\Services\SeriesEditions\SeriesEditionReconciler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A reverted merge moves solving times and round puzzles back to the restored puzzle - reconcile like after the merge
 * itself (ReconcileRoundResultsOnPuzzleMergeApproved).
 */
#[AsMessageHandler]
readonly final class ReconcileRoundResultsOnPuzzleMergeReverted
{
    public function __construct(
        private SeriesEditionReconciler $reconciler,
    ) {
    }

    public function __invoke(PuzzleMergeReverted $event): void
    {
        $this->reconciler->reconcile();
    }
}
