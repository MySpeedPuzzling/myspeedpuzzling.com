<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecordImportSheetParsed;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportSheetRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The end of a sheet's parse, under the import's lock - refused when the sheet has a newer mapping meanwhile: the
 * sheet's counts and the version they are of. From here the sheet is parsed: no job of this version starts again.
 *
 * Its equal rows were numbered before (RecordEqualImportRows); rows equal to a row of another sheet are linked
 * next (LinkEqualImportRows).
 */
#[AsMessageHandler]
readonly final class RecordImportSheetParsedHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportSheetRepository $dataImportSheetRepository,
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

        $import->workedOn($this->clock->now());

        return true;
    }
}
