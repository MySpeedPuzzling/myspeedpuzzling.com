<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Exceptions\ImportMappingChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;
use SpeedPuzzling\Web\Message\ParseImportSheet;
use SpeedPuzzling\Web\Message\StartImportSheetOver;
use SpeedPuzzling\Web\Query\GetImportSheetRows;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportSheetRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportColumnDecisions;
use SpeedPuzzling\Web\Services\DataImport\ImportSheetAnalyser;
use SpeedPuzzling\Web\Services\DataImport\SheetLookupFactory;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImport\ColumnCertainty;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportSheetKind;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "Start over for this sheet" (docs/features/data-import/layouts.md), under the import's lock: the sheet is
 * recognised again from its own rows as if the player had never saved a layout (ImportSheetAnalyser, then
 * ImportColumnDecisions), stored as its next `mapping_version`, and read again - with the sheets that look puzzles
 * up in it. It counts as the player's word on the sheet: nothing confirmed on another sheet is laid over it again.
 * Nothing is saved as a layout, and the saved one is not changed.
 *
 * What only the whole workbook tells is kept: the sheet it looks puzzles up in (while it still needs one), and that
 * it is the list other sheets point to.
 */
#[AsMessageHandler]
readonly final class StartImportSheetOverHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportSheetRepository $dataImportSheetRepository,
        private GetImportSheetRows $rows,
        private ImportSheetAnalyser $analyser,
        private ImportColumnDecisions $decisions,
        private MessageBusInterface $messageBus,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DataImportNotFound
     * @throws ImportMappingChangedMeanwhile
     */
    public function __invoke(StartImportSheetOver $message): void
    {
        $import = $this->dataImportRepository->getOwnedBy($message->importId, $message->playerId);
        $this->idLock->lockUntilCommit($import->id);

        $sheets = $this->dataImportSheetRepository->ofImport($import);
        $sheet = null;

        foreach ($sheets as $candidate) {
            if ($candidate->id->toString() === strtolower($message->sheetId)) {
                $sheet = $candidate;
            }
        }

        if ($sheet === null) {
            throw new DataImportNotFound();
        }

        if ($import->isBeingSetUp() === false || $sheet->mappingVersion === 0 || $sheet->mappingVersion !== $message->mappingVersion) {
            throw new ImportMappingChangedMeanwhile();
        }

        $sheetId = $sheet->id->toString();
        $proposal = $this->analyser->analyse($this->rows->sample($sheetId));
        $mapping = $proposal->mapping;
        $options = $proposal->options;

        try {
            $before = ImportSheetOptions::fromArray($sheet->options);
        } catch (InvalidImportMapping) {
            $before = new ImportSheetOptions();
        }

        if ($before->lookupSheetId !== null && SheetLookupFactory::needsALookup($mapping)) {
            $options = $options->withLookupSheet($before->lookupSheetId);
        }

        if ($sheet->kind === DataImportSheetKind::PuzzleList && $mapping->kind === DataImportSheetKind::Owned) {
            $mapping = $mapping->withKind(DataImportSheetKind::PuzzleList, ColumnCertainty::Certain);
        }

        [$options, $questions] = $this->decisions->settle(
            $mapping,
            $options,
            $sheet->title,
            $this->rows->rows($sheetId, DataImportLimits::COLUMN_DECISION_ROWS, $mapping->table()->firstRow ?? 1, $mapping->table()?->lastRow),
        );

        // Checked like any mapping that is stored
        $mapping = ImportMapping::fromArray($mapping->withQuestions($questions)->toArray());
        $sheet->mappingConfirmed($mapping, $options, $proposal->valueAnswers);
        $this->messageBus->dispatch(new ParseImportSheet($message->importId, $sheetId, $sheet->mappingVersion));

        foreach ($sheets as $other) {
            if ($other === $sheet || $other->mappingVersion === 0 || ($other->options['lookupSheetId'] ?? null) !== $sheetId) {
                continue;
            }

            $other->lookedUpSheetChanged();
            $this->messageBus->dispatch(new ParseImportSheet($message->importId, $other->id->toString(), $other->mappingVersion));
        }

        $import->workedOn($this->clock->now());
    }
}
