<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecordEqualImportRows;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\DataImportSheetRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Under the import's lock, on fresh state, only for the sheet's current mapping version. The numbers were worked
 * out from each whole group of equal rows (by a key the numbering never changes), so writing them again - a second
 * job, a second delivery - writes the same.
 */
#[AsMessageHandler]
readonly final class RecordEqualImportRowsHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportSheetRepository $dataImportSheetRepository,
        private DataImportRowRepository $dataImportRowRepository,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordEqualImportRows $message): bool
    {
        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->find($message->importId);
        $sheet = $import === null ? null : $this->dataImportSheetRepository->find($message->sheetId);

        if ($import === null || $sheet === null || $sheet->import !== $import || $import->isBeingSetUp() === false || $sheet->mappingVersion !== $message->mappingVersion) {
            return false;
        }

        foreach ($this->dataImportRowRepository->ofSheetByIds($sheet, array_keys($message->rows)) as $id => $row) {
            if ($row->canBeParsed()) {
                $numbered = $message->rows[$id];
                $row->numberedAmongEqualRows(
                    $numbered['occurrence'],
                    $numbered['sourceKey'],
                    // A reference: the row it points at is not loaded for it
                    $numbered['sameAs'] === null ? null : $this->dataImportRowRepository->reference($numbered['sameAs']),
                );
            }
        }

        $sheet->parseGoesOn($this->clock->now());

        return true;
    }
}
