<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use League\Flysystem\FilesystemException;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Exceptions\ImportUploadRefused;
use SpeedPuzzling\Web\Message\ConfirmImportUploaded;
use SpeedPuzzling\Web\Message\ReadImportFile;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Services\DataImport\ImportSlots;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImportStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * One metadata request to object storage inside the transaction - milliseconds, and the only row read is the
 * player's own import. An import that becomes `uploaded` is queued for reading at once (ReadImportFile, on the
 * `imports` queue - docs/features/data-import/reading.md).
 */
#[AsMessageHandler]
readonly final class ConfirmImportUploadedHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private ImportSlots $importSlots,
        private ImportFileStorage $storage,
        private IdLock $idLock,
        private ClockInterface $clock,
        private MessageBusInterface $messageBus,
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
        // The same lock as the start's: the count below can not be passed by two confirmations at once
        $this->idLock->lockUntilCommit($import->player->id);

        if ($import->status === DataImportStatus::Uploaded) {
            // Confirmed before - the same confirmation sent again
            return;
        }

        if ($import->status !== DataImportStatus::Uploading || $import->fileKey === null) {
            throw new ImportUploadRefused('state');
        }

        // The start counts the uploads on their way too - checked again here, should one have got around it
        if ($this->importSlots->isFullFor($import->player->id->toString(), withUploadsOnTheirWay: false)) {
            throw new ImportUploadRefused('too_many');
        }

        $bytes = $this->storage->storedBytes($import->fileKey);

        if ($bytes === null) {
            throw new ImportUploadRefused('missing');
        }

        if ($bytes !== $import->fileSize) {
            throw new ImportUploadRefused('size');
        }

        $import->uploadConfirmed($this->clock->now());

        // Queued on the same connection as this transaction: the message exists exactly when the import is `uploaded`
        // (one that gets lost all the same is found by myspeedpuzzling:read-waiting-imports)
        $this->messageBus->dispatch(new ReadImportFile($message->importId));
    }
}
