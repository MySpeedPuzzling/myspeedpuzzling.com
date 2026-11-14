<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Message\DiscardImport;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\DataImportSheetRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Value\DataImportStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Nothing an import can add exists yet, so a discarded import has nothing worth keeping: its rows and sheets go now,
 * its file too, and the daily prune removes what is left of it (PruneDataImportsHandler). A file object storage would
 * not delete right now keeps its key - the prune tries again.
 */
#[AsMessageHandler]
readonly final class DiscardImportHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportSheetRepository $dataImportSheetRepository,
        private DataImportRowRepository $dataImportRowRepository,
        private ImportFileStorage $storage,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws DataImportNotFound
     */
    public function __invoke(DiscardImport $message): void
    {
        $import = $this->dataImportRepository->getOwnedBy($message->importId, $message->playerId);

        if ($import->status === DataImportStatus::Discarded) {
            return;
        }

        $now = $this->clock->now();

        $this->dataImportRowRepository->deleteOfImport($import);
        $this->dataImportSheetRepository->deleteOfImport($import);
        $import->discard($now);

        if ($import->fileKey === null) {
            return;
        }

        try {
            $this->storage->delete($import->fileKey);
            $import->fileRemoved($now);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not delete the file of a discarded import - the daily prune tries again', [
                'import_id' => $message->importId,
                'exception' => $e,
            ]);
        }
    }
}
