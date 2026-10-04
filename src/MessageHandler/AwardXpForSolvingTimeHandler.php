<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\AwardXpForSolvingTime;
use SpeedPuzzling\Web\Services\Xp\XpChainRecomputer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AwardXpForSolvingTimeHandler
{
    public function __construct(
        private XpChainRecomputer $xpChainRecomputer,
    ) {
    }

    public function __invoke(AwardXpForSolvingTime $message): void
    {
        $this->xpChainRecomputer->awardForNewSolve($message->solvingTimeId);
    }
}
