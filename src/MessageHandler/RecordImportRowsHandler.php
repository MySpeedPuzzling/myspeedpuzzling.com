<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportRow;
use SpeedPuzzling\Web\Message\RecordImportRows;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\DataImportSheetRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportProgress;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One batch of rows, one short transaction - under the import's lock, which a discard, a "try again" and the rescue
 * of a stalled read take too: rows are never written into an import that is not being read by this very read.
 */
#[AsMessageHandler]
readonly final class RecordImportRowsHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportSheetRepository $dataImportSheetRepository,
        private DataImportRowRepository $dataImportRowRepository,
        private ImportProgress $progress,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return bool whether reading goes on
     */
    public function __invoke(RecordImportRows $message): bool
    {
        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->find($message->importId);

        if ($import === null || $import->isBeingReadBy($message->readAttempt) === false) {
            return false;
        }

        $sheet = $this->dataImportSheetRepository->find($message->sheetId);

        if ($sheet === null || $sheet->import !== $import) {
            return false;
        }

        foreach ($message->rows as $read) {
            $row = new DataImportRow(Uuid::uuid7(), $import, $sheet, $read['number'], $import->version, $import->version);
            $row->cells = $read['cells'];
            $this->dataImportRowRepository->save($row);
        }

        $sheet->rowsStored(count($message->rows), $message->lastColumn);
        $this->progress->store(
            $import,
            $import->sheetsTotal,
            $import->sheetsRead + ($message->sheetRead ? 1 : 0),
            $import->rowsRead + count($message->rows),
            $this->clock->now(),
        );

        return true;
    }
}
