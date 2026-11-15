<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\LinkEqualImportRows;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One statement over the import's rows, under the import's lock (DataImportRowRepository::linkEqualRowsAcrossSheets()
 * says why it is a bulk statement).
 */
#[AsMessageHandler]
readonly final class LinkEqualImportRowsHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportRowRepository $dataImportRowRepository,
        private IdLock $idLock,
    ) {
    }

    public function __invoke(LinkEqualImportRows $message): void
    {
        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->find($message->importId);

        if ($import !== null && $import->isBeingSetUp()) {
            $this->dataImportRowRepository->linkEqualRowsAcrossSheets($import);
        }
    }
}
