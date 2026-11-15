<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;
use SpeedPuzzling\Web\Message\ParseImportSheet;
use SpeedPuzzling\Web\Message\RecordSharedImportMappings;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportSheetRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Stores what ShareImportLayout worked out - under the import's lock, which a confirmation and a discard take too.
 * Only for a sheet that is still at the version it was worked out for and that the player has not confirmed
 * meanwhile: **a sheet the player confirmed is never overwritten**. A changed sheet gets its next version; it, every
 * sheet that waits for its rows to be read, and the sheets that look puzzles up in a changed one are parsed.
 */
#[AsMessageHandler]
readonly final class RecordSharedImportMappingsHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportSheetRepository $dataImportSheetRepository,
        private MessageBusInterface $messageBus,
        private IdLock $idLock,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecordSharedImportMappings $message): void
    {
        if (Uuid::isValid($message->importId) === false) {
            return;
        }

        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->find($message->importId);

        if ($import === null || $import->isBeingSetUp() === false) {
            return;
        }

        $sheets = $this->dataImportSheetRepository->ofImport($import);

        foreach ($sheets as $sheet) {
            $sheetId = $sheet->id->toString();
            $worked = $message->sheets[$sheetId] ?? null;

            if ($worked === null || $sheet->confirmedByPlayer || $sheet->mappingVersion !== $worked['version']) {
                continue;
            }

            $changed = false;

            if ($worked['mapping'] !== null && $worked['options'] !== null) {
                try {
                    /** @var array<int, array<string, string>> $valueAnswers */
                    $valueAnswers = $sheet->valueAnswers ?? [];
                    $changed = $sheet->mappingShared(ImportMapping::fromArray($worked['mapping']), ImportSheetOptions::fromArray($worked['options']), $valueAnswers);
                } catch (InvalidImportMapping $e) {
                    // Ours to fix - the sheet keeps the mapping it had and is read as it is
                    $this->logger->error('The mapping a sheet of an import was to follow can not be stored', [
                        'import_id' => $message->importId,
                        'sheet_id' => $sheetId,
                        'exception' => $e,
                    ]);
                }
            }

            if ($changed || $sheet->waitsForItsParse()) {
                $this->messageBus->dispatch(new ParseImportSheet($message->importId, $sheetId, $sheet->mappingVersion));
            }

            foreach ($changed ? $sheets : [] as $other) {
                if ($other === $sheet || $other->mappingVersion === 0 || ($other->options['lookupSheetId'] ?? null) !== $sheetId) {
                    continue;
                }

                // As after a confirmed mapping: whoever looks puzzles up in the sheet reads its rows again
                $other->lookedUpSheetChanged();
                $this->messageBus->dispatch(new ParseImportSheet($message->importId, $other->id->toString(), $other->mappingVersion));
            }
        }

        $import->workedOn($this->clock->now());
    }
}
