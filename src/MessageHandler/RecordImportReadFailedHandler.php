<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecordImportReadFailed;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\DataImportSheetRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImportStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A read that ended without the rows leaves none behind: the sheets and rows written so far are removed (the
 * repositories' bulk deletes - up to 50,000 rows that must not be loaded to be removed), the import says why.
 */
#[AsMessageHandler]
readonly final class RecordImportReadFailedHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportSheetRepository $dataImportSheetRepository,
        private DataImportRowRepository $dataImportRowRepository,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return bool whether the import was failed by this
     */
    public function __invoke(RecordImportReadFailed $message): bool
    {
        if (Uuid::isValid($message->importId) === false) {
            return false;
        }

        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->find($message->importId);

        if ($import === null || $import->status !== DataImportStatus::Reading) {
            return false;
        }

        if ($message->readAttempt !== null && $import->isBeingReadBy($message->readAttempt) === false) {
            return false;
        }

        // The rescue of a stalled read: a batch that arrived meanwhile means the worker lives
        if ($message->withoutProgressSince !== null && $import->lastActivityAt >= $message->withoutProgressSince) {
            return false;
        }

        if ($message->readAttempt === null && $message->withoutProgressSince === null) {
            return false;
        }

        $this->dataImportRowRepository->deleteOfImport($import);
        $this->dataImportSheetRepository->deleteOfImport($import);
        $import->readingFailed($message->reason, $this->clock->now());

        return true;
    }
}
