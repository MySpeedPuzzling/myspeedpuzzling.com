<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ProposeImportMappings;
use SpeedPuzzling\Web\Message\RecordImportRead;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class RecordImportReadHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private IdLock $idLock,
        private MessageBusInterface $messageBus,
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
        // In this transaction, on the queue's own connection: the message exists exactly when the import is read
        $this->messageBus->dispatch(new ProposeImportMappings($message->importId));

        return true;
    }
}
