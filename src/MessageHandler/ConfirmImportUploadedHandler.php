<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use League\Flysystem\FilesystemException;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Exceptions\ImportUploadRefused;
use SpeedPuzzling\Web\Message\ConfirmImportUploaded;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Value\DataImportStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One metadata request to object storage inside the transaction - milliseconds, and the only row read is the
 * player's own import. Reading the file is not queued here yet: `uploaded` is where an import rests until the reader
 * is built (docs/features/data-import/upload.md).
 */
#[AsMessageHandler]
readonly final class ConfirmImportUploadedHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private ImportFileStorage $storage,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DataImportNotFound
     * @throws ImportUploadRefused
     * @throws FilesystemException object storage does not answer - nothing changes, the confirmation can be sent again
     */
    public function __invoke(ConfirmImportUploaded $message): void
    {
        $import = $this->dataImportRepository->getOwnedBy($message->importId, $message->playerId);

        if ($import->status === DataImportStatus::Uploaded) {
            // Confirmed before - the same confirmation sent again
            return;
        }

        if ($import->status !== DataImportStatus::Uploading || $import->fileKey === null) {
            throw new ImportUploadRefused('state');
        }

        $bytes = $this->storage->storedBytes($import->fileKey);

        if ($bytes === null) {
            throw new ImportUploadRefused('missing');
        }

        if ($bytes !== $import->fileSize) {
            throw new ImportUploadRefused('size');
        }

        $import->uploadConfirmed($this->clock->now());
    }
}
