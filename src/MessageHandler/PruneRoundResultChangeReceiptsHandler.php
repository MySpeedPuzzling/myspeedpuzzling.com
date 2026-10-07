<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\PruneRoundResultChangeReceipts;
use SpeedPuzzling\Web\Repository\RoundResultChangeReceiptRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Receipts of official results changes (RoundResultChangeReceipt) are needed only while a device may still send a
 * change again - its outbox empties within minutes once it is online. 90 days leave room for a phone forgotten in a
 * drawer; after that a replay falls back to the three-way check alone.
 */
#[AsMessageHandler]
readonly final class PruneRoundResultChangeReceiptsHandler
{
    public function __construct(
        private RoundResultChangeReceiptRepository $receiptRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PruneRoundResultChangeReceipts $message): int
    {
        return $this->receiptRepository->deleteOlderThan($this->clock->now()->modify("-{$message->retentionDays} days"));
    }
}
