<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\BuildImportPuzzles;
use SpeedPuzzling\Web\Message\RequestImportPairing;
use SpeedPuzzling\Web\Repository\DataImportPuzzleRepository;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Under the import's lock: stamped (the import's activity - the next runs of the safety net leave it alone for a
 * quarter of an hour - and the count that bounds the tries) and queued in one transaction.
 */
#[AsMessageHandler]
readonly final class RequestImportPairingHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportPuzzleRepository $dataImportPuzzleRepository,
        private MessageBusInterface $messageBus,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RequestImportPairing $message): bool
    {
        if (Uuid::isValid($message->importId) === false) {
            return false;
        }

        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->find($message->importId);

        if ($import === null || $import->pairingRequestedAgain($this->clock->now(), $this->dataImportPuzzleRepository->hasUndecidedARunWouldLookAt($import)) === false) {
            return false;
        }

        $this->messageBus->dispatch(new BuildImportPuzzles($message->importId));

        return true;
    }
}
