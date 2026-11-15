<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Message\FetchImportAgain;
use SpeedPuzzling\Web\Message\StartImportFromGoogleSheet;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class FetchImportAgainHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private MessageBusInterface $messageBus,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DataImportNotFound
     */
    public function __invoke(FetchImportAgain $message): bool
    {
        $import = $this->dataImportRepository->getOwnedBy($message->importId, $message->playerId);
        $this->idLock->lockUntilCommit($import->id);

        if ($import->fetchAgain($this->clock->now()) === false) {
            return false;
        }

        // Queued on the same connection as this transaction: the message exists exactly when the state does
        $this->messageBus->dispatch(new StartImportFromGoogleSheet($message->importId));

        return true;
    }
}
