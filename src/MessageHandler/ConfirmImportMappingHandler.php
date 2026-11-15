<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\DataImportSheet;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Exceptions\ImportMappingChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;
use SpeedPuzzling\Web\Message\ConfirmImportMapping;
use SpeedPuzzling\Web\Message\ParseImportSheet;
use SpeedPuzzling\Web\Query\GetImportSheetRows;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportSheetRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportColumnDecisions;
use SpeedPuzzling\Web\Services\DataImport\ImportLayouts;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImport\ColumnCertainty;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;
use SpeedPuzzling\Web\Value\DataImport\ImportText;
use SpeedPuzzling\Web\Value\DataImport\ImportWordMeaning;
use SpeedPuzzling\Web\Value\DataImport\MappedColumn;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\PuzzlingType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The player's changed mapping or answers for one sheet (docs/features/data-import/columns.md "Confirming"), under
 * the import's lock:
 *
 * 1. the import is the player's and is being set up; the sheet is its; the version is the one the player saw;
 * 2. answers are checked against the sheet's questions; a mapping is checked whole (ImportMapping::fromArray()):
 *    columns inside the table, never two on one single-valued field. What the player changed is "certain, by the
 *    player"; nothing a browser sends about certainty, reasons, roles or a known export is taken;
 * 3. how times and dates are read is settled again for the new mapping (answers kept) - what is left is asked;
 * 4. the sheet gets its next `mapping_version` and its parsing is queued (the rows a question that is left decides
 *    wait - PendingQuestionRows) - and that of every sheet
 *    that looks puzzles up in it, each with a new version of its own, so no older job of theirs writes on.
 *
 * 5. what the player said is remembered as the layout of sheets with this header row, and the sheets of the same
 *    workbook with that header row which the player has not confirmed themselves follow at once (ImportLayouts,
 *    docs/features/data-import/layouts.md).
 *
 * Nothing but this sheet (and those) is parsed again.
 */
#[AsMessageHandler]
readonly final class ConfirmImportMappingHandler
{
    private const int MAX_WORDS_PER_COLUMN = 500;

    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportSheetRepository $dataImportSheetRepository,
        private GetImportSheetRows $rows,
        private ImportColumnDecisions $decisions,
        private ImportLayouts $layouts,
        private MessageBusInterface $messageBus,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DataImportNotFound
     * @throws ImportMappingChangedMeanwhile
     * @throws InvalidImportMapping
     */
    public function __invoke(ConfirmImportMapping $message): void
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

        $current = ImportMapping::fromArray($sheet->mapping ?? []);
        $options = $this->decisions->answered($current, ImportSheetOptions::fromArray($sheet->options), $message->answers);
        $mapping = $message->mapping === null ? $current : self::asThePlayerSaid(ImportMapping::fromArray($message->mapping), $current);
        $options = $this->sheetWide($options, $message->sheetWide, $sheet, $sheets);
        $valueAnswers = $message->valueAnswers === null ? $sheet->valueAnswers ?? [] : self::valueAnswers($message->valueAnswers, $mapping);

        [$options, $questions] = $this->decisions->settle(
            $mapping,
            $options,
            $sheet->title,
            // Only the table's own rows, and only as many as are looked at: this runs under the import's lock
            $this->rows->rows($sheet->id->toString(), DataImportLimits::COLUMN_DECISION_ROWS, $mapping->table()->firstRow ?? 1, $mapping->table()?->lastRow),
        );

        $mapping = $mapping->withQuestions($questions);
        $sheet->mappingConfirmed($mapping, $options, $valueAnswers);

        // Also for a sheet that is "not puzzles" now (its rows are left out by the same job) and for one that still
        // asks something: the rows its questions decide wait, the others are read (PendingQuestionRows)
        $this->messageBus->dispatch(new ParseImportSheet($message->importId, $sheet->id->toString(), $sheet->mappingVersion));

        foreach ($sheets as $other) {
            if ($other === $sheet || $other->mappingVersion === 0 || ($other->options['lookupSheetId'] ?? null) !== $sheet->id->toString()) {
                continue;
            }

            // A new version of its own: an older job of that sheet, still reading the old list, writes nothing more
            $other->lookedUpSheetChanged();

            $this->messageBus->dispatch(new ParseImportSheet($message->importId, $other->id->toString(), $other->mappingVersion));
        }

        $this->layouts->confirmed($import, $sheet, $current, $sheets);

        $import->workedOn($this->clock->now());
    }

    /**
     * The submitted mapping with only what a player decides taken from it: the kind, what a row is, which table,
     * where the tables are, and what each column is. A column the player left as it was keeps how sure we were; a
     * changed one is certain, by the player. A role (the source's row id …) and a known export are never claimed by a
     * browser - they stay only where they were.
     */
    private static function asThePlayerSaid(ImportMapping $submitted, ImportMapping $current): ImportMapping
    {
        $known = [];

        foreach ($current->columns as $column) {
            $known[$column->column] = $column;
        }

        $sameTable = $submitted->table()?->toArray() === $current->table()?->toArray();
        $columns = [];

        foreach ($submitted->columns as $column) {
            $before = $sameTable ? $known[$column->column] ?? null : null;

            if ($before !== null && $before->field === $column->field && $before->readyMade === $column->readyMade && $before->role === $column->role) {
                $columns[] = $before;

                continue;
            }

            if ($column->role !== null) {
                throw new InvalidImportMapping('A column\'s role is not for the player to set.');
            }

            $columns[] = new MappedColumn(
                $column->column,
                $before === null ? $column->header : $before->header,
                $column->field,
                ColumnCertainty::Certain,
                MappedColumn::BY_PLAYER,
                $column->readyMade,
                null,
                null,
                $column->field === ImportField::Time ? $before?->unit : null,
            );
        }

        return new ImportMapping(
            $submitted->kind,
            ColumnCertainty::Certain,
            $submitted->rowMeaning,
            $submitted->regions,
            $submitted->region,
            $columns,
            [],
            $sameTable ? $current->layout : null,
        );
    }

    /**
     * @param null|array<mixed> $sheetWide
     * @param list<DataImportSheet> $sheets
     */
    private function sheetWide(ImportSheetOptions $options, null|array $sheetWide, DataImportSheet $sheet, array $sheets): ImportSheetOptions
    {
        if ($sheetWide === null) {
            return $options;
        }

        if (array_key_exists('solvingType', $sheetWide)) {
            $type = is_string($sheetWide['solvingType']) ? PuzzlingType::tryFrom($sheetWide['solvingType']) : null;

            if ($sheetWide['solvingType'] !== null && $type === null) {
                throw new InvalidImportMapping('"Every row is" must be solo, duo or team.');
            }

            $options = $options->withSolvingType($type);
        }

        if (array_key_exists('collectionLabel', $sheetWide)) {
            $label = $sheetWide['collectionLabel'];

            if ($label !== null && (is_string($label) === false || mb_strlen($label) > 200)) {
                throw new InvalidImportMapping('The collection\'s name must be a short text.');
            }

            $options = $options->withCollectionLabel($label === null || trim($label) === '' ? null : trim($label));
        }

        if (array_key_exists('lookupSheetId', $sheetWide)) {
            $lookup = $sheetWide['lookupSheetId'];
            $ofThisImport = array_filter($sheets, static fn (DataImportSheet $other): bool => $other !== $sheet && $other->id->toString() === $lookup);

            if ($lookup !== null && $ofThisImport === []) {
                throw new InvalidImportMapping('Puzzles are looked up in another sheet of the same import.');
            }

            $options = $options->withLookupSheet(is_string($lookup) ? $lookup : null);
        }

        return $options;
    }

    /**
     * @param array<mixed> $submitted
     *
     * @return array<int, array<string, string>> column number => word as it is compared => meaning
     */
    private static function valueAnswers(array $submitted, ImportMapping $mapping): array
    {
        $columns = array_map(static fn (MappedColumn $column): int => $column->column, $mapping->columns);
        $answers = [];

        if (count($submitted) > DataImportLimits::MAX_REGION_COLUMNS) {
            throw new InvalidImportMapping('The meanings of words belong to columns of the table.');
        }

        foreach ($submitted as $column => $words) {
            if (in_array((int) $column, $columns, true) === false || is_array($words) === false || count($words) > self::MAX_WORDS_PER_COLUMN) {
                throw new InvalidImportMapping('The meanings of words belong to columns of the table.');
            }

            foreach ($words as $word => $meaning) {
                if (is_string($meaning) === false || ImportWordMeaning::tryFrom($meaning) === null || mb_strlen((string) $word) > 200) {
                    throw new InvalidImportMapping('A word\'s meaning is not one we know.');
                }

                $answers[(int) $column][ImportText::plain(ImportText::clean((string) $word))] = $meaning;
            }
        }

        return $answers;
    }
}
