<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecordImportBatchSettled;
use SpeedPuzzling\Web\Repository\DataImportBatchRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RecordImportBatchSettledHandler
{
    public function __construct(
        private DataImportBatchRepository $batchRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordImportBatchSettled $message): void
    {
        $this->batchRepository->find($message->batchId)?->settledSoFar(
            $message->upToRowId === null ? null : Uuid::fromString($message->upToRowId),
            $message->through,
            $this->clock->now(),
        );
    }
}
