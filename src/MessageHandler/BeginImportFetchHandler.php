<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\BeginImportFetch;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\ImportFetchJob;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Under the import's lock: of two workers that got the same message one fetches, the other finds nothing to do.
 */
#[AsMessageHandler]
readonly final class BeginImportFetchHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(BeginImportFetch $message): null|ImportFetchJob
    {
        if (Uuid::isValid($message->importId) === false) {
            return null;
        }

        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->find($message->importId);

        if ($import === null || $import->fetchStarted($this->clock->now()) === false) {
            return null;
        }

        assert($import->googleSpreadsheetId !== null && $import->fileKey !== null);

        return new ImportFetchJob($message->importId, $import->fetchAttempt, $import->googleSpreadsheetId, $import->fileKey);
    }
}
