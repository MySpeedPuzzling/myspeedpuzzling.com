<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportRow;
use SpeedPuzzling\Web\Message\RecordImportSheetParsed;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\DataImportSheetRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportEqualRows;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The end of a sheet's parse, in one transaction under the import's lock - refused when the sheet has a newer
 * mapping meanwhile:
 *
 * 1. the sheet's counts and the version they are of;
 * 2. rows of the sheet that are equal in all a key holds are numbered (the occurrence becomes part of a result's
 *    key) and linked - one result unless the player says two, attempt numbers telling a log of repeats apart
 *    (ImportEqualRows);
 *
 * Rows equal to a row of another sheet are linked next, by LinkEqualImportRows - a transaction of its own, because
 * it compares the keys this one has just written.
 */
#[AsMessageHandler]
readonly final class RecordImportSheetParsedHandler
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
     * @return bool whether it was stored
     */
    public function __invoke(RecordImportSheetParsed $message): bool
    {
        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->find($message->importId);
        $sheet = $import === null ? null : $this->dataImportSheetRepository->find($message->sheetId);

        if ($import === null || $sheet === null || $sheet->import !== $import || $import->isBeingSetUp() === false || $sheet->mappingVersion !== $message->mappingVersion) {
            return false;
        }

        $sheet->parsed($message->mappingVersion, $message->rowsUnderstood, $message->rowsNeedLook, $message->rowsLeftOut);

        $groups = [];

        foreach ($this->dataImportRowRepository->withAKeySharedOnTheirSheet($sheet) as $row) {
            if ($row->canBeParsed()) {
                $groups[(string) $row->sourceKey][$row->id->toString()] = $row;
            }
        }

        foreach ($groups as $rows) {
            $this->numberAndLink($rows);
        }

        $import->workedOn($this->clock->now());

        return true;
    }

    /**
     * @param array<string, DataImportRow> $rows rows of one sheet with the same key, by id
     */
    private function numberAndLink(array $rows): void
    {
        $facts = [];

        foreach ($rows as $id => $row) {
            $attempt = $row->parsed['attempt'] ?? null;
            $content = $row->parsed['content'] ?? '';
            $facts[] = ['id' => $id, 'attempt' => is_int($attempt) ? $attempt : null, 'content' => is_string($content) ? $content : '', 'rowNumber' => $row->rowNumber];
        }

        foreach (ImportEqualRows::number($facts) as $id => $numbered) {
            $row = $rows[$id];
            $isResult = $row->kind === DataImportRowKind::Result || $row->kind === DataImportRowKind::Both;

            $row->numberedAmongEqualRows(
                $numbered['occurrence'],
                // Only a result's key holds the occurrence: two rows for one owned puzzle are one puzzle
                $isResult && $row->puzzleKey !== null ? ImportEqualRows::resultKey($row->parsed ?? [], $row->puzzleKey, $numbered['occurrence']) : null,
                $numbered['sameAs'] === null ? null : $rows[$numbered['sameAs']],
            );
        }
    }
}
