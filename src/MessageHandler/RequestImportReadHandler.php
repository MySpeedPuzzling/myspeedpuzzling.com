<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ReadImportFile;
use SpeedPuzzling\Web\Message\RequestImportRead;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImportLimits;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class RequestImportReadHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private MessageBusInterface $messageBus,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RequestImportRead $message): bool
    {
        if (Uuid::isValid($message->importId) === false) {
            return false;
        }

        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->find($message->importId);
        $now = $this->clock->now();

        if ($import === null || $import->readRequestedAgain($now, $now->modify('-' . DataImportLimits::READ_REQUESTED_AGAIN_AFTER_SECONDS . ' seconds')) === false) {
            return false;
        }

        // Queued on the same connection as this transaction: the message exists exactly when the stamp does
        $this->messageBus->dispatch(new ReadImportFile($message->importId));

        return true;
    }
}
