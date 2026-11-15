<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\BeginImportReading;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\ImportReadingJob;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * `uploaded` → `reading`, under the import's lock: of two workers that got the same message one reads, the other
 * finds nothing to do (docs/features/data-import/reading.md "The read, step by step").
 */
#[AsMessageHandler]
readonly final class BeginImportReadingHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(BeginImportReading $message): null|ImportReadingJob
    {
        if (Uuid::isValid($message->importId) === false) {
            return null;
        }

        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->find($message->importId);

        if ($import === null || $import->fileKind === null || $import->fileSize === null || $import->readingStarted($this->clock->now()) === false) {
            return null;
        }

        assert($import->fileKey !== null);

        return new ImportReadingJob($message->importId, $import->readAttempt, $import->fileKey, $import->fileKind, $import->fileSize);
    }
}
