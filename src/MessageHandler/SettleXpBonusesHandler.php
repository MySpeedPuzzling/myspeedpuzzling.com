<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\SettleXpBonuses;
use SpeedPuzzling\Web\Message\SettleXpBonusesOfPlayer;
use SpeedPuzzling\Web\Services\Xp\XpChainRecomputer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Runs outside a transaction (SettleXpBonuses is HandledOutsideTransaction): reads who gets something paid, then each
 * player's settlement is a message of its own, committed on its own. A run that fails midway keeps what it committed;
 * the next run (or the transport's retry) settles the rest - a settled bonus is never pending again.
 */
#[AsMessageHandler]
readonly final class SettleXpBonusesHandler
{
    public function __construct(
        private XpChainRecomputer $xpChainRecomputer,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SettleXpBonuses $message): void
    {
        $settled = 0;

        foreach ($this->xpChainRecomputer->playersWithPendingSettlements() as $playerId) {
            $result = $this->messageBus->dispatch(new SettleXpBonusesOfPlayer($playerId))
                ->last(HandledStamp::class)
                ?->getResult();

            $settled += is_int($result) ? $result : 0;
        }

        if ($settled > 0) {
            $this->logger->info('Settled pending XP bonuses', ['entries' => $settled]);
        }
    }
}
