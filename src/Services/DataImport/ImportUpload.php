<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use League\Flysystem\FilesystemException;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\ImportUploadRefused;
use SpeedPuzzling\Web\Message\ConfirmImportUploaded;
use SpeedPuzzling\Web\Message\StartImport;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Value\DataImportFileKind;
use SpeedPuzzling\Web\Value\DataImportLimits;
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
        private ClockInterface $clock,
    ) {
    }

    /**
     * Whether the player's upload of this id can be gone on with (the same import, still uploading, the same size):
     * start() then only signs it again and receive() puts the form's file into it - no new import, and no new
     * upload for the rate limit.
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
            && $import->fileSize === $bytes
            // The prune removes an unconfirmed upload after a day - nothing is signed for it in its last hour, so
            // no upload URL outlives the row (DataImportLimits::SIGNATURE_GRACE_HOURS)
            && $import->createdAt > $this->clock->now()->modify('-' . (DataImportLimits::UNCONFIRMED_UPLOAD_HOURS - DataImportLimits::SIGNATURE_GRACE_HOURS) . ' hours');
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
     * Whether receive() would put the form's file into the upload the page's script started for it (the direct way
     * did not work after its start) instead of making a second import.
     */
    public function continues(string $playerId, null|string $importId, UploadedFile $file): bool
    {
        $bytes = $file->getSize();

        if ($bytes === false || $this->isRetry($playerId, $importId, $bytes) === false || $importId === null) {
            return false;
        }

        return $this->dataImportRepository->find($importId)?->fileKind === DataImportFileKind::ofFileName(trim($file->getClientOriginalName()));
    }

    /**
     * @param null|string $importId the upload the page's script started for this very file - gone on with when it is
     *        (continues()), so one file is one import
     *
     * @return string the import's id
     *
     * @throws ImportUploadRefused
     * @throws FilesystemException object storage does not take the file - the import stays `uploading` for the prune
     */
    public function receive(string $playerId, UploadedFile $file, null|string $importId = null): string
    {
        $bytes = $file->getSize();

        if ($importId === null || $this->continues($playerId, $importId, $file) === false) {
            $importId = Uuid::uuid7()->toString();
            $this->dispatch(new StartImport($importId, $playerId, $file->getClientOriginalName(), $bytes === false ? 0 : $bytes));
        }

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
