<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportSheet;
use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;
use SpeedPuzzling\Web\Message\ParseImportSheet;
use SpeedPuzzling\Web\Message\RecordImportMappings;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportSheetRepository;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetProposal;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Stores the import's proposals as version 1 of each sheet that has no mapping yet - under the import's lock, which
 * a discard takes too - and queues, in the same transaction, the parsing of every sheet that is data and needs no
 * question (for a sheet that is "not puzzles" the parse leaves its rows out). A sheet with a question waits for the
 * player; a sheet whose proposal can not be stored ends failed - the others are not held up by it.
 */
#[AsMessageHandler]
readonly final class RecordImportMappingsHandler
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

    /**
     * @return list<string> the sheets whose parsing was queued
     */
    public function __invoke(RecordImportMappings $message): array
    {
        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->find($message->importId);

        if ($import === null || $import->isBeingSetUp() === false) {
            return [];
        }

        $queued = [];

        foreach ($this->dataImportSheetRepository->ofImport($import) as $sheet) {
            $stored = $message->proposals[$sheet->id->toString()] ?? null;

            if ($stored === null) {
                continue;
            }

            if ($sheet->mappingVersion !== 0 || $sheet->setUpFailure !== null) {
                continue;
            }

            try {
                $proposal = new ImportSheetProposal(
                    ImportMapping::fromArray($stored['mapping']),
                    ImportSheetOptions::fromArray($stored['options']),
                    $stored['valueAnswers'],
                    $stored['headerSignature'],
                );
            } catch (InvalidImportMapping $e) {
                // A proposal of ours that is no mapping is a bug of ours - for this sheet only: the sheet ends
                // failed, visibly, and is not proposed again and again; the other sheets go on
                $sheet->setUpFailed(DataImportSheet::SET_UP_MAPPING_INVALID);
                $this->logger->error('The mapping proposed for a sheet of an import can not be stored', [
                    'import_id' => $message->importId,
                    'sheet_id' => $sheet->id->toString(),
                    'exception' => $e,
                ]);

                continue;
            }

            $sheet->mappingProposed($proposal);

            // Also a sheet that is "not puzzles": its rows are left out by the same job as after a confirmed mapping
            if ($proposal->mapping->needsAnAnswer() === false) {
                $this->messageBus->dispatch(new ParseImportSheet($message->importId, $sheet->id->toString(), $sheet->mappingVersion));
                $queued[] = $sheet->id->toString();
            }
        }

        $import->workedOn($this->clock->now());

        return $queued;
    }
}
