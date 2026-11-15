<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\RecordImportRowFailed;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Value\DataImport\ImportRowRefusal;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Only a row that is still queued in the batch: its transaction was rolled back, so nothing of it was written.
 */
#[AsMessageHandler]
readonly final class RecordImportRowFailedHandler
{
    public function __construct(
        private DataImportRowRepository $rowRepository,
    ) {
    }

    public function __invoke(RecordImportRowFailed $message): void
    {
        $row = $this->rowRepository->find($message->rowId);

        if ($row !== null && $row->isQueuedIn($message->batchId)) {
            $row->failed(ImportRowRefusal::Error->value);
        }
    }
}
