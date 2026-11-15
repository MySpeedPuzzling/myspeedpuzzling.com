<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecordImportFileHash;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportFileStorage;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "The same file again" (docs/features/data-import/README.md "Reading a file": answered when reading starts, once
 * the worker knows the file's hash). When the player already has an import that was read - or is being read - from
 * the very same bytes, this one is closed in favour of that one: discarded, its file deleted, and it remembers where
 * the player goes on (`same_file_as_import_id` - the import's page sends them there with a notice).
 *
 * Under the player's lock: of two uploads of one file read side by side, the second to know its hash is the one
 * that closes.
 */
#[AsMessageHandler]
readonly final class RecordImportFileHashHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private ImportFileStorage $storage,
        private IdLock $idLock,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool whether reading goes on
     */
    public function __invoke(RecordImportFileHash $message): bool
    {
        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->find($message->importId);

        if ($import === null || $import->isBeingReadBy($message->readAttempt) === false) {
            return false;
        }

        $this->idLock->lockUntilCommit($import->player->id);
        $import->fileHashed($message->sha256);
        $same = $this->dataImportRepository->findLiveOfSameFile($import, $message->sha256);

        if ($same === null) {
            return true;
        }

        $import->closedAsSameFileAs($same, $this->clock->now());

        // Like a discard: the key stays on the row, the daily prune deletes the object once more before the row goes
        // (docs/features/data-import/upload.md "A file must not come back")
        if ($import->fileKey !== null) {
            try {
                $this->storage->delete($import->fileKey);
            } catch (\Throwable $e) {
                $this->logger->warning('Could not delete the file of an import closed as the same file - the daily prune tries again', [
                    'import_id' => $message->importId,
                    'exception' => $e,
                ]);
            }
        }

        return false;
    }
}
