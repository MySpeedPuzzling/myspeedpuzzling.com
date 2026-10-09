<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Events\PuzzleMergeApproved;
use SpeedPuzzling\Web\Services\SeriesEditions\SeriesEditionReconciler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A merge moves solving times and round puzzles onto the surviving puzzle, possibly across several
 * competitions - merges are rare, so reconcile everything rather than tracking which competitions moved: every
 * series' picks (their puzzle may have changed, P28), then every competition's round results.
 */
#[AsMessageHandler]
readonly final class ReconcileRoundResultsOnPuzzleMergeApproved
{
    public function __construct(
        private SeriesEditionReconciler $reconciler,
    ) {
    }

    public function __invoke(PuzzleMergeApproved $event): void
    {
        $this->reconciler->reconcile();
    }
}
