<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
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
 * answer. A sheet with a question waits for the player; "not puzzles" is not parsed.
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

            $proposal = new ImportSheetProposal(
                ImportMapping::fromArray($stored['mapping']),
                ImportSheetOptions::fromArray($stored['options']),
                $stored['valueAnswers'],
                $stored['headerSignature'],
            );

            if ($sheet->mappingProposed($proposal) === false) {
                continue;
            }

            if ($proposal->mapping->isImported() === false) {
                // Not puzzles: nothing of it is read - there is nothing to wait for
                $sheet->parsed($sheet->mappingVersion, 0, 0, 0);
            } elseif ($proposal->mapping->needsAnAnswer() === false) {
                $this->messageBus->dispatch(new ParseImportSheet($message->importId, $sheet->id->toString(), $sheet->mappingVersion));
                $queued[] = $sheet->id->toString();
            }
        }

        $import->workedOn($this->clock->now());

        return $queued;
    }
}
