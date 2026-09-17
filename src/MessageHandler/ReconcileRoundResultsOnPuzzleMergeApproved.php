<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Events\PuzzleMergeApproved;
use SpeedPuzzling\Web\Services\RoundResults\RoundResultsReconciler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A merge moves solving times and round puzzles onto the surviving puzzle, possibly across several
 * competitions - merges are rare, so reconcile everything rather than tracking which competitions moved.
 */
#[AsMessageHandler]
readonly final class ReconcileRoundResultsOnPuzzleMergeApproved
{
    public function __construct(
        private RoundResultsReconciler $reconciler,
    ) {
    }

    public function __invoke(PuzzleMergeApproved $event): void
    {
        $this->reconciler->reconcile();
    }
}
