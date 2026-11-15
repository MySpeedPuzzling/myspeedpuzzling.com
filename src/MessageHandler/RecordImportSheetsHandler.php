<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportSheet;
use SpeedPuzzling\Web\Message\RecordImportSheets;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportSheetRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportProgress;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RecordImportSheetsHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportSheetRepository $dataImportSheetRepository,
        private ImportProgress $progress,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return bool whether reading goes on
     */
    public function __invoke(RecordImportSheets $message): bool
    {
        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->find($message->importId);

        if ($import === null || $import->isBeingReadBy($message->readAttempt) === false) {
            return false;
        }

        foreach ($message->sheets as $found) {
            $sheet = new DataImportSheet(Uuid::fromString($found['id']), $import, $found['position'], $found['title']);
            $sheet->hidden = $found['hidden'];
            $sheet->hints = $found['hints'];
            $this->dataImportSheetRepository->save($sheet);
        }

        $this->progress->store($import, count($message->sheets), 0, 0, $this->clock->now());

        return true;
    }
}
