<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\RecalculateXpForPlayer;
use SpeedPuzzling\Web\Services\Xp\XpRecomputer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RecalculateXpForPlayerHandler
{
    public function __construct(
        private XpRecomputer $xpRecomputer,
    ) {
    }

    public function __invoke(RecalculateXpForPlayer $message): void
    {
        $this->xpRecomputer->recomputeForPlayer($message->playerId);
    }
}
