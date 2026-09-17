<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\ReconcileRoundResults;
use SpeedPuzzling\Web\Services\RoundResults\RoundResultsReconciler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ReconcileRoundResultsHandler
{
    public function __construct(
        private RoundResultsReconciler $reconciler,
    ) {
    }

    /**
     * @return array{linked: int, unlinked: int}
     */
    public function __invoke(ReconcileRoundResults $message): array
    {
        return $this->reconciler->reconcile();
    }
}
