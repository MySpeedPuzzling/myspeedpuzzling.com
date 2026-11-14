<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use League\Flysystem\FilesystemException;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\ImportUploadRefused;
use SpeedPuzzling\Web\Message\ConfirmImportUploaded;
use SpeedPuzzling\Web\Message\StartImport;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Value\DataImportStatus;
use SpeedPuzzling\Web\Value\PresignedImportUpload;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The two ways a spreadsheet reaches an import (docs/features/data-import/upload.md) - both through the same
 * messages, so the same limits hold:
 * - start(): the browser uploads the file itself, straight to object storage (no byte goes through PHP: 25 MB from a
 *   phone on a slow connection never holds a worker nor meets the proxy's timeout) and confirms it afterwards;
 * - receive(): the fallback - the file came with the plain form, is stored and confirmed here.
 *
 * Every state change is a message (StartImport, ConfirmImportUploaded); this only strings them together.
 */
readonly final class ImportUpload
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private DataImportRepository $dataImportRepository,
        private ImportFileStorage $storage,
    ) {
    }

    /**
     * Whether start() would only sign an upload again (the same import, still uploading, the same size) - that is no
     * new upload for the rate limit.
     */
    public function isRetry(string $playerId, null|string $importId, int $bytes): bool
    {
        if ($importId === null) {
            return false;
        }

        $import = $this->dataImportRepository->find($importId);

        return $import !== null
            && $import->isOwnedBy(strtolower($playerId))
            && $import->status === DataImportStatus::Uploading
            && $import->fileSize === $bytes;
    }

    /**
     * @param null|string $importId an upload of this player to sign again (its URL ran out) - else a new import
     *
     * @return array{importId: string, upload: PresignedImportUpload}
     *
     * @throws ImportUploadRefused
     */
    public function start(string $playerId, string $fileName, int $bytes, null|string $importId = null): array
    {
        if ($this->isRetry($playerId, $importId, $bytes) === false) {
            $importId = Uuid::uuid7()->toString();
            $this->dispatch(new StartImport($importId, $playerId, $fileName, $bytes));
        }

        assert($importId !== null);

        $upload = $this->storage->presignedUpload($this->dataImportRepository->getOwnedBy($importId, $playerId));

        if ($upload === null) {
            throw new ImportUploadRefused('state');
        }

        return ['importId' => $importId, 'upload' => $upload];
    }

    /**
     * @return string the new import's id
     *
     * @throws ImportUploadRefused
     * @throws FilesystemException object storage does not take the file - the import stays `uploading` for the prune
     */
    public function receive(string $playerId, UploadedFile $file): string
    {
        $bytes = $file->getSize();
        $importId = Uuid::uuid7()->toString();

        $this->dispatch(new StartImport($importId, $playerId, $file->getClientOriginalName(), $bytes === false ? 0 : $bytes));

        $import = $this->dataImportRepository->getOwnedBy($importId, $playerId);

        if ($import->fileKey === null || $import->fileKind === null) {
            throw new ImportUploadRefused('state');
        }

        $this->storage->store($import->fileKey, $file->getPathname(), $import->fileKind);
        $this->dispatch(new ConfirmImportUploaded($importId, $playerId));

        return $importId;
    }

    /**
     * @throws ImportUploadRefused
     */
    private function dispatch(object $message): void
    {
        try {
            $this->messageBus->dispatch($message);
        } catch (HandlerFailedException $exception) {
            $refusal = $exception->getPrevious();

            if ($refusal instanceof ImportUploadRefused) {
                throw $refusal;
            }

            throw $exception;
        }
    }
}
