<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportSheet;
use SpeedPuzzling\Web\Message\BeginImportSheetParse;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportSheetRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImportLimits;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The claim of a sheet's parse, committed before a row is read: of several jobs for the same sheet and version
 * exactly one goes on. A sheet whose parse was started DataImportLimits::MAX_SET_UP_ATTEMPTS times and never ended
 * (a worker that dies on it every time) is failed here - visibly, logged once, and never queued again.
 */
#[AsMessageHandler]
readonly final class BeginImportSheetParseHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportSheetRepository $dataImportSheetRepository,
        private IdLock $idLock,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(BeginImportSheetParse $message): bool
    {
        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->find($message->importId);
        $sheet = $import === null ? null : $this->dataImportSheetRepository->find($message->sheetId);

        if ($import === null || $sheet === null || $sheet->import !== $import || $import->isBeingSetUp() === false) {
            return false;
        }

        $now = $this->clock->now();
        $claim = $sheet->claimParse($message->mappingVersion, $now, $now->modify('-' . DataImportLimits::PARSE_CLAIM_SECONDS . ' seconds'));

        if ($claim === DataImportSheet::PARSE_GIVEN_UP) {
            $this->logger->error('A sheet of an import was started to be parsed several times and never finished - given up', [
                'import_id' => $message->importId,
                'sheet_id' => $message->sheetId,
                'mapping_version' => $message->mappingVersion,
            ]);
        }

        if ($claim === DataImportSheet::PARSE_CLAIMED) {
            $import->workedOn($now);
        }

        return $claim === DataImportSheet::PARSE_CLAIMED;
    }
}
