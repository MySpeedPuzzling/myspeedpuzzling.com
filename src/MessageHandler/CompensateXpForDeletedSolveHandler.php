<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\CompensateXpForDeletedSolve;
use SpeedPuzzling\Web\Services\Xp\XpChainRecomputer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class CompensateXpForDeletedSolveHandler
{
    public function __construct(
        private XpChainRecomputer $xpChainRecomputer,
    ) {
    }

    public function __invoke(CompensateXpForDeletedSolve $message): void
    {
        $this->xpChainRecomputer->compensateAndRebuildAfterDeletion($message->solvingTimeId, $message->puzzleId);
    }
}
