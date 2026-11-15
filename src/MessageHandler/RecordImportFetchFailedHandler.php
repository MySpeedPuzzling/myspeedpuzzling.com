<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecordImportFetchFailed;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImportStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RecordImportFetchFailedHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return bool whether the import was failed by this
     */
    public function __invoke(RecordImportFetchFailed $message): bool
    {
        if (Uuid::isValid($message->importId) === false) {
            return false;
        }

        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->find($message->importId);

        if ($import === null || $import->status !== DataImportStatus::Fetching) {
            return false;
        }

        if ($message->fetchAttempt !== null && $import->isBeingFetchedBy($message->fetchAttempt) === false) {
            return false;
        }

        // The rescue: something happened meanwhile - a worker took it, or the player tried again
        if ($message->withoutProgressSince !== null && $import->lastActivityAt >= $message->withoutProgressSince) {
            return false;
        }

        if ($message->fetchAttempt === null && $message->withoutProgressSince === null) {
            return false;
        }

        $import->fetchFailed($message->reason, $this->clock->now());

        return true;
    }
}
