<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecordImportRead;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RecordImportReadHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return bool whether the import is read now
     */
    public function __invoke(RecordImportRead $message): bool
    {
        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->find($message->importId);

        if ($import === null || $import->isBeingReadBy($message->readAttempt) === false) {
            return false;
        }

        $import->readingFinished($this->clock->now());

        return true;
    }
}
