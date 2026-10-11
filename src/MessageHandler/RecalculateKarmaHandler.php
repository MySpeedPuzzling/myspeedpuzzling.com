<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\RecalculateKarma;
use SpeedPuzzling\Web\Services\Karma\KarmaRecalculator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RecalculateKarmaHandler
{
    public function __construct(
        private KarmaRecalculator $karmaRecalculator,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecalculateKarma $message): void
    {
        if ($message->playerIds === null) {
            $changed = $this->karmaRecalculator->recalculateAll();

            // The daily safety net: after the handlers' own recalculations nothing should be left to change
            $this->logger->info('Karma recalculated for every player, {changed} changed', ['changed' => $changed]);

            return;
        }

        $this->karmaRecalculator->recalculateFor($message->playerIds);
    }
}
