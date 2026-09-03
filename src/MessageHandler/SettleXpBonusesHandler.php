<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\SettleXpBonuses;
use SpeedPuzzling\Web\Services\Xp\XpChainRecomputer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class SettleXpBonusesHandler
{
    public function __construct(
        private XpChainRecomputer $xpChainRecomputer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SettleXpBonuses $message): void
    {
        $settled = $this->xpChainRecomputer->settlePendingBonuses();

        if ($settled > 0) {
            $this->logger->info('Settled pending XP bonuses', ['entries' => $settled]);
        }
    }
}
