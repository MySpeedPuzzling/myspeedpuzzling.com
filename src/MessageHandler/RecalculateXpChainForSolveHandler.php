<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\RecalculateXpChainForSolve;
use SpeedPuzzling\Web\Services\Xp\XpChainRecomputer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RecalculateXpChainForSolveHandler
{
    public function __construct(
        private XpChainRecomputer $xpChainRecomputer,
    ) {
    }

    public function __invoke(RecalculateXpChainForSolve $message): void
    {
        $this->xpChainRecomputer->rebuildChainForEditedSolve($message->solvingTimeId);
    }
}
