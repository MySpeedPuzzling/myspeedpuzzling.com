<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\SettleXpBonusesOfPlayer;
use SpeedPuzzling\Web\Services\Xp\XpChainRecomputer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class SettleXpBonusesOfPlayerHandler
{
    public function __construct(
        private XpChainRecomputer $xpChainRecomputer,
    ) {
    }

    /**
     * @return int the number of settlement entries created
     */
    public function __invoke(SettleXpBonusesOfPlayer $message): int
    {
        return $this->xpChainRecomputer->settlePendingBonusesOf($message->playerId);
    }
}
