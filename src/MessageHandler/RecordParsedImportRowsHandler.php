<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecordParsedImportRows;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\DataImportSheetRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One batch of parsed rows, one short transaction - under the import's lock, which a confirmed mapping and a
 * discard take too, and on fresh state: rows are written only while the sheet's mapping is the version they were
 * parsed for. A row something was already made of (queued, added …) keeps what it was parsed as.
 */
#[AsMessageHandler]
readonly final class RecordParsedImportRowsHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportSheetRepository $dataImportSheetRepository,
        private DataImportRowRepository $dataImportRowRepository,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return bool whether parsing goes on
     */
    public function __invoke(RecordParsedImportRows $message): bool
    {
        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->find($message->importId);
        $sheet = $import === null ? null : $this->dataImportSheetRepository->find($message->sheetId);

        if ($import === null || $sheet === null || $sheet->import !== $import || $import->isBeingSetUp() === false || $sheet->mappingVersion !== $message->mappingVersion) {
            return false;
        }

        foreach ($this->dataImportRowRepository->ofSheetByIds($sheet, array_keys($message->rows)) as $id => $row) {
            if ($row->canBeParsed()) {
                $row->parsedAs($message->mappingVersion, $message->rows[$id]);
            }
        }

        // The claim stays fresh while batches arrive
        $sheet->parseGoesOn($this->clock->now());
        $import->workedOn($this->clock->now());

        return true;
    }
}
