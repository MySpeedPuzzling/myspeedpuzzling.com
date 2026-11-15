<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Message\ReadImportAgain;
use SpeedPuzzling\Web\Message\ReadImportFile;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\DataImportSheetRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class ReadImportAgainHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportSheetRepository $dataImportSheetRepository,
        private DataImportRowRepository $dataImportRowRepository,
        private MessageBusInterface $messageBus,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return bool whether the import waits for the worker again (false: it is not one whose reading failed, or its
     *         file is gone)
     *
     * @throws DataImportNotFound
     */
    public function __invoke(ReadImportAgain $message): bool
    {
        $import = $this->dataImportRepository->getOwnedBy($message->importId, $message->playerId);
        $this->idLock->lockUntilCommit($import->id);

        if ($import->readAgain($this->clock->now()) === false) {
            return false;
        }

        // A failed read removed what it wrote - once more, should anything have stayed
        $this->dataImportRowRepository->deleteOfImport($import);
        $this->dataImportSheetRepository->deleteOfImport($import);

        // Queued on the same connection as this transaction: the message exists exactly when the state does
        $this->messageBus->dispatch(new ReadImportFile($message->importId));

        return true;
    }
}
