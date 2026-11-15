<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecordImportFetched;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RecordImportFetchedHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return bool whether the import now has its file
     */
    public function __invoke(RecordImportFetched $message): bool
    {
        if (Uuid::isValid($message->importId) === false) {
            return false;
        }

        // The lock a discard, "Try again" and the rescue take too
        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->find($message->importId);

        if ($import === null || $import->isBeingFetchedBy($message->fetchAttempt) === false) {
            return false;
        }

        $import->fetched($message->fileName, $message->fileSize, $this->clock->now());

        return true;
    }
}
