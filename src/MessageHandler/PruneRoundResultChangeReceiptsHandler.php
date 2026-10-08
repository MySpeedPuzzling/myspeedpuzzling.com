<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\PruneRoundResultChangeReceipts;
use SpeedPuzzling\Web\Repository\ParticipantSheetChangeReceiptRepository;
use SpeedPuzzling\Web\Repository\RoundResultChangeReceiptRepository;
use SpeedPuzzling\Web\Results\PrunedChangeReceipts;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Receipts of official results changes (RoundResultChangeReceipt) and of participants sheet change sets
 * (ParticipantSheetChangeReceipt) are needed only while a device or a page may still send the same changes again - an
 * outbox empties within minutes once it is online. 90 days leave room for a phone forgotten in a drawer; after that a
 * replay falls back to the three-way check alone. One cron for both (docs/TODO.md, lily.srv).
 */
#[AsMessageHandler]
readonly final class PruneRoundResultChangeReceiptsHandler
{
    public function __construct(
        private RoundResultChangeReceiptRepository $receiptRepository,
        private ParticipantSheetChangeReceiptRepository $sheetReceiptRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PruneRoundResultChangeReceipts $message): PrunedChangeReceipts
    {
        $before = $this->clock->now()->modify("-{$message->retentionDays} days");

        return new PrunedChangeReceipts(
            roundResultReceipts: $this->receiptRepository->deleteOlderThan($before),
            participantSheetReceipts: $this->sheetReceiptRepository->deleteReceivedBefore($before),
        );
    }
}
