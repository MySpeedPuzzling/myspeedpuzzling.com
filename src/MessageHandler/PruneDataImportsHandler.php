<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Message\PruneDataImports;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Value\DataImportLimits;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The imports' daily clean-up (docs/features/data-import/upload.md "Prune"):
 * - an upload the browser never confirmed within DataImportLimits::UNCONFIRMED_UPLOAD_HOURS: whatever arrived is
 *   deleted and the import with it (it holds nothing);
 * - a file older than DataImportLimits::KEEP_FILE_DAYS: the object is deleted, the import stays, marked "file removed";
 * - a discarded import: its file if it is still there, then the import whole.
 *
 * An object that object storage will not delete today keeps its import as it is - tomorrow's run tries again, so a
 * file never stays behind without the row that knows its key.
 */
#[AsMessageHandler]
readonly final class PruneDataImportsHandler
{
    // Per kind and run - far above a day's imports; a backlog is worked off over the following runs
    private const int BATCH = 500;

    public function __construct(
        private DataImportRepository $dataImportRepository,
        private ImportFileStorage $storage,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{unconfirmed: int, files: int, discarded: int}
     */
    public function __invoke(PruneDataImports $message): array
    {
        $now = $this->clock->now();
        $removed = ['unconfirmed' => 0, 'files' => 0, 'discarded' => 0];

        $unconfirmedBefore = $now->modify('-' . DataImportLimits::UNCONFIRMED_UPLOAD_HOURS . ' hours');

        foreach ($this->dataImportRepository->uploadsStartedBefore($unconfirmedBefore, self::BATCH) as $import) {
            if ($this->deleteFile($import)) {
                $this->dataImportRepository->delete($import);
                $removed['unconfirmed']++;
            }
        }

        $filesBefore = $now->modify('-' . DataImportLimits::KEEP_FILE_DAYS . ' days');

        foreach ($this->dataImportRepository->withFileCreatedBefore($filesBefore, self::BATCH) as $import) {
            if ($this->deleteFile($import)) {
                $import->fileRemoved($now);
                $removed['files']++;
            }
        }

        foreach ($this->dataImportRepository->discarded(self::BATCH) as $import) {
            if ($this->deleteFile($import)) {
                $this->dataImportRepository->delete($import);
                $removed['discarded']++;
            }
        }

        return $removed;
    }

    private function deleteFile(DataImport $import): bool
    {
        if ($import->fileKey === null) {
            return true;
        }

        try {
            $this->storage->delete($import->fileKey);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Could not delete an import\'s file - the next daily prune tries again', [
                'import_id' => $import->id->toString(),
                'exception' => $e,
            ]);

            return false;
        }
    }
}
