<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\RecordDataImportsPruned;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Value\DataImportStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The rows are read again here: between the prune's look and this write an import may have been confirmed or
 * discarded - its object is deleted either way, so what is written follows the state the row has now.
 */
#[AsMessageHandler]
readonly final class RecordDataImportsPrunedHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordDataImportsPruned $message): void
    {
        $now = $this->clock->now();

        foreach ($message->removeImportIds as $importId) {
            $import = $this->dataImportRepository->find($importId);

            if ($import === null) {
                continue;
            }

            if (in_array($import->status, [DataImportStatus::Uploading, DataImportStatus::Discarded], true)) {
                $this->dataImportRepository->delete($import);

                continue;
            }

            // Confirmed in the meantime - the import stays, but its file is gone
            $import->fileRemoved($now);
        }

        foreach ($message->fileRemovedImportIds as $importId) {
            $import = $this->dataImportRepository->find($importId);

            // A discarded import keeps its key until the prune removes it whole
            if ($import !== null && $import->status !== DataImportStatus::Discarded) {
                $import->fileRemoved($now);
            }
        }
    }
}
