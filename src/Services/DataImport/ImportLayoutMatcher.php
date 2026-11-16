<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;
use SpeedPuzzling\Web\Services\DataImport\Reading\HeaderMatcher;
use SpeedPuzzling\Web\Value\DataImport\ColumnCertainty;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\ImportLayoutContent;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportQuestion;
use SpeedPuzzling\Web\Value\DataImport\ImportQuestionType;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetProposal;
use SpeedPuzzling\Web\Value\DataImport\KnownExportLayout;
use SpeedPuzzling\Web\Value\DataImport\MappedColumn;
use SpeedPuzzling\Web\Value\DataImport\SolvingTypeWords;
use SpeedPuzzling\Web\Value\DataImport\RememberedLayout;
use SpeedPuzzling\Web\Value\DataImport\SavedImportLayout;
use SpeedPuzzling\Web\Value\DataImportRowMeaning;
use SpeedPuzzling\Web\Value\DataImportSheetKind;
use SpeedPuzzling\Web\Value\SearchText;
use SpeedPuzzling\Web\Value\StandardCustomField;

/**
 * Knows a sheet's layout again and applies what the player set up for it last time
 * (docs/features/data-import/layouts.md). Pure - no database.
 *
 * - **The signature** of a sheet is its header row as it is compared: every header folded (HeaderMatcher::words()),
 *   a header that stands there twice numbered, left to right. A sheet without a header row has none - and is never
 *   remembered.
 * - **The same headers** (in any order): every column is as the player set it up.
 * - **At least SHARE_TO_APPLY of the headers in common:** the columns both have are as the player set them up, the
 *   others are proposed as usual.
 * - **A layout carries the columns - never a certainty the sheet does not support:** a remembered column is "certain"
 *   only when the sheet's own values go with it (else it is one to check); what the sheet is (results / owned / wish
 *   list, what a row is) is taken over only when the sheet's own name and columns say the same, or the sheet has the
 *   name of the one the layout was saved from; "every row is a pair result" only for a sheet of that name.
 * - **Answers** (how a shape of time is read, day or month first, what "Jan 23" means) answer only a question about
 *   the very column they were given for, only one the new sheet really asks, only with a reading its rows still
 *   allow, and never against what its rows point to - then the question is asked again.
 */
final readonly class ImportLayoutMatcher
{
    public const float SHARE_TO_APPLY = 0.8;
    /** The share of a column's values that must go with a remembered field for the column to be "certain" */
    public const float VALUES_FIT_FROM = 0.6;

    private const string SIGNATURE_VERSION = 'L1:';
    private const int ANSWER_PASSES = 3;

    public function __construct(
        private SheetKindGuesser $kindGuesser,
        private ImportColumnDecisions $decisions,
        private ColumnRecogniser $columnRecogniser,
    ) {
    }

    /**
     * What each column of the sheet's table is known by: its header as it is compared, numbered when the same
     * header stands there more than once ("notes#1", "notes#2"). A column without a header has no key.
     *
     * @return array<int, string> column number => key, left to right
     */
    public static function keysByColumn(ImportMapping $mapping): array
    {
        if ($mapping->table()?->headerRow === null) {
            return [];
        }

        $columns = $mapping->columns;
        usort($columns, static fn (MappedColumn $a, MappedColumn $b): int => $a->column <=> $b->column);
        $seen = [];
        $keys = [];

        foreach ($columns as $column) {
            $words = HeaderMatcher::words($column->header);

            if ($words === '') {
                continue;
            }

            $seen[$words] = ($seen[$words] ?? 0) + 1;
            $keys[$column->column] = $words . '#' . $seen[$words];
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    public static function headerKeys(ImportMapping $mapping): array
    {
        return array_values(self::keysByColumn($mapping));
    }

    /**
     * Null for a sheet without a header row: nothing to know it by.
     */
    public static function signature(ImportMapping $mapping): null|string
    {
        $keys = self::headerKeys($mapping);

        return $keys === [] ? null : self::SIGNATURE_VERSION . hash('sha256', implode("\n", $keys));
    }

    /**
     * The saved layout that fits the sheet, and whether it fits the whole of it: the same signature, else the same
     * headers in another order, else the one sharing most headers (SHARE_TO_APPLY at least) - of two alike the one
     * used last. A known export is the player's layout's only when they changed what that export's columns are.
     *
     * @param list<SavedImportLayout> $layouts
     *
     * @return null|array{SavedImportLayout, bool}
     */
    public static function matching(ImportMapping $mapping, array $layouts): null|array
    {
        $keys = self::headerKeys($mapping);

        if ($keys === []) {
            return null;
        }

        $signature = self::signature($mapping);
        $best = null;
        $bestRank = null;

        foreach ($layouts as $layout) {
            if ($layout->headers === [] || ($mapping->layout !== null && $layout->changesKnownExport === false)) {
                continue;
            }

            $common = count(array_intersect($keys, $layout->headers));
            $share = $common / max(count($keys), count($layout->headers));
            $whole = $layout->signature === $signature || ($common === count($keys) && $common === count($layout->headers));

            if ($whole === false && $share < self::SHARE_TO_APPLY) {
                continue;
            }

            $rank = [$layout->signature === $signature ? 2 : ($whole ? 1 : 0), $share, $layout->lastUsedAt->getTimestamp(), $layout->id];

            if ($bestRank === null || $rank > $bestRank) {
                $best = [$layout, $whole];
                $bestRank = $rank;
            }
        }

        return $best;
    }

    /**
     * The sheet's own proposal with the fitting layout applied - or as it was, when none fits.
     *
     * @param ImportSheetProposal $proposal the sheet's OWN proposal (ImportSheetAnalyser) - what it says by itself is
     *        what a layout is checked against
     * @param list<SavedImportLayout> $layouts
     * @param iterable<int, array<int, array<string, mixed>>> $rows a sample of the sheet's rows (row number => cells
     *        by column): what the remembered columns are checked against
     */
    public function apply(string $sheetTitle, ImportSheetProposal $proposal, array $layouts, iterable $rows = []): ImportSheetProposal
    {
        $match = self::matching($proposal->mapping, $layouts);

        if ($match === null) {
            return $proposal;
        }

        [$layout, $whole] = $match;
        $mapping = $proposal->mapping;
        $content = $layout->content;
        $keys = self::keysByColumn($mapping);
        $values = self::valuesByColumn($mapping, $rows);
        $columns = [];
        $slots = [];
        $remembered = [];

        foreach ($mapping->columns as $index => $own) {
            $column = $own;
            $known = $content->columns[$keys[$own->column] ?? ''] ?? null;

            // A role (the source's own row id …) is a known export's to give, never a layout's
            if ($known !== null && $own->role === null) {
                $fits = $this->fits($own, $known['field'], $known['readyMade'], $values[$own->column] ?? []);
                $column = new MappedColumn(
                    $own->column,
                    $own->header,
                    $known['field'],
                    // What the sheet's own values do not go with is the player's to check - never ours to be sure of
                    $fits ? ColumnCertainty::Certain : ColumnCertainty::Probable,
                    MappedColumn::BY_REMEMBERED,
                    $known['readyMade'],
                    null,
                    $fits || $own->field === $known['field'] ? null : $own->field,
                    $known['field'] === ImportField::Time ? $own->unit : null,
                );
                $remembered[$index] = true;
            }

            if (isset($remembered[$index]) || $column->role !== null) {
                $slot = self::singleSlot($column);

                // Two remembered columns on one field can not be: the later one is left as it was proposed
                if ($slot !== null && isset($slots[$slot])) {
                    $column = $own;
                    unset($remembered[$index]);
                } elseif ($slot !== null) {
                    $slots[$slot] = true;
                }
            }

            $columns[$index] = $column;
        }

        if ($remembered === []) {
            return $proposal;
        }

        // Never two columns on one single-valued field: what the player set up stays, a column proposed beside it
        // that wants the same field is left unknown - with what it would have been on offer
        foreach ($columns as $index => $column) {
            $slot = isset($remembered[$index]) || $column->role !== null ? null : self::singleSlot($column);

            if ($slot === null) {
                continue;
            }

            if (isset($slots[$slot])) {
                $columns[$index] = $column->asUnknown(MappedColumn::BY_DUPLICATE);
            } else {
                $slots[$slot] = true;
            }
        }

        $columns = array_values($columns);

        // What the sheet is, by itself: its name and its columns as they stand now
        [$kind, $certainty, $rowMeaning] = $this->kindGuesser->guess($sheetTitle, $columns, $mapping->layout === null ? null : KnownExportLayout::byId($mapping->layout));
        // "The puzzle list the other sheets point to" is found across the workbook each time - by itself the sheet
        // is a list of owned puzzles
        $saidKind = $content->kind === DataImportSheetKind::PuzzleList ? DataImportSheetKind::Owned : $content->kind;
        $saidMeaning = $content->rowMeaning;
        $hasStatus = array_filter($columns, static fn (MappedColumn $column): bool => $column->field === ImportField::Status) !== [];
        $sameName = self::titleKey($sheetTitle) === self::titleKey($content->title);
        $sayable = $saidMeaning !== DataImportRowMeaning::ByColumn || $hasStatus;

        // The player's word about a sheet of this name - or the sheet says the same by itself. Never across sheets
        // whose names differ ("Pairs" / "Solo", "Collection" / "Wishlist") when the sheet itself says otherwise
        if ($sayable && ($sameName || ($kind === $saidKind && $rowMeaning === $saidMeaning))) {
            [$kind, $certainty, $rowMeaning] = [$saidKind, ColumnCertainty::Certain, $saidMeaning];
        }

        // "Every row is a pair result" goes with a sheet of the same name - and never onto a sheet whose title says
        // another kind, by the one list of those words (SolvingTypeWords)
        $solvingType = $sameName && SolvingTypeWords::titleDisagrees($sheetTitle, $content->solvingType) === false ? $content->solvingType : null;
        $meaningKept = $kind !== $saidKind || $rowMeaning !== $saidMeaning || $solvingType !== $content->solvingType;

        $applied = new ImportMapping(
            $kind,
            $certainty,
            $rowMeaning,
            $mapping->regions,
            $mapping->region,
            $columns,
            [],
            $mapping->layout,
            new RememberedLayout($whole ? RememberedLayout::WHOLE : RememberedLayout::PARTLY, $layout->id, mb_substr($layout->name, 0, 200), $meaningKept),
        );

        try {
            // What would be refused as a stored mapping is not applied: the sheet keeps its own proposal
            ImportMapping::fromArray($applied->toArray());
        } catch (InvalidImportMapping) {
            return $proposal;
        }

        $options = $solvingType === null ? $proposal->options : $proposal->options->withSolvingType($solvingType);

        return new ImportSheetProposal($applied, $options, $proposal->valueAnswers, $proposal->headerSignature);
    }

    /**
     * What two sheets must share for one's "what this sheet is" to be the other's: the name, folded, without its
     * digits ("Results 2021" and "Results 2022" are one name; "Pairs" and "Solo" are two).
     */
    public static function titleKey(string $sheetTitle): string
    {
        return trim((string) preg_replace('/[^\p{L}]+/u', ' ', SearchText::fold($sheetTitle)));
    }

    /**
     * Whether the sheet's own values go with what the player said the column is: the sheet's own proposal says the
     * same - or nothing in its values speaks against it (VALUES_FIT_FROM of them are read as that field, and the
     * recogniser did not take them for something else).
     *
     * @param list<array<string, mixed>> $values
     */
    private function fits(MappedColumn $own, null|ImportField $field, null|StandardCustomField $readyMade, array $values): bool
    {
        // "Don't import" claims nothing about the values
        if ($field === null || ($own->field === $field && $own->readyMade === $readyMade)) {
            return true;
        }

        $valuesSayOtherwise = $own->field !== null
            && $own->field !== ImportField::Computed
            && in_array($own->reason, [MappedColumn::BY_VALUES, MappedColumn::BY_HEADER_AND_VALUES], true);

        return $valuesSayOtherwise === false && $this->columnRecogniser->valuesFit($field, $values) >= self::VALUES_FIT_FROM;
    }

    /**
     * @param iterable<int, array<int, array<string, mixed>>> $rows
     *
     * @return array<int, list<array<string, mixed>>> column => up to ColumnRecogniser::SAMPLE values of the table
     */
    private static function valuesByColumn(ImportMapping $mapping, iterable $rows): array
    {
        $table = $mapping->table();
        $values = [];

        if ($table === null) {
            return [];
        }

        foreach ($rows as $number => $cells) {
            if ($table->holdsRow($number) === false) {
                continue;
            }

            foreach ($cells as $column => $cell) {
                if ($table->holdsColumn($column) && StoredCells::isBlank($cell) === false && count($values[$column] ?? []) < ColumnRecogniser::SAMPLE) {
                    $values[$column][] = $cell;
                }
            }
        }

        return $values;
    }

    /**
     * The puzzle list a remembered layout looked brand and pieces up in, found again in this workbook: a sheet of
     * the same title with the same header row - never by an id of another import. What the workbook's own names
     * already found (SheetLookupFactory::link()) is left as it is.
     *
     * @param array<string, ImportSheetProposal> $proposals by sheet id
     * @param array<string, string> $titles by sheet id
     * @param list<SavedImportLayout> $layouts
     *
     * @return array<string, ImportSheetProposal>
     */
    public static function withRememberedLookups(array $proposals, array $titles, array $layouts): array
    {
        $lookups = [];

        foreach ($layouts as $layout) {
            $lookups[$layout->id] = $layout->content->lookup;
        }

        foreach ($proposals as $sheetId => $proposal) {
            $lookup = $lookups[$proposal->mapping->remembered->id ?? ''] ?? null;

            if ($lookup === null || $proposal->options->lookupSheetId !== null || SheetLookupFactory::needsALookup($proposal->mapping) === false) {
                continue;
            }

            foreach ($proposals as $listId => $list) {
                $couldBe = in_array($list->mapping->kind, [DataImportSheetKind::Owned, DataImportSheetKind::PuzzleList], true) && $list->mapping->has(ImportField::PuzzleName);

                if ($listId === $sheetId || $couldBe === false || ($titles[$listId] ?? null) !== $lookup['title'] || self::signature($list->mapping) !== $lookup['signature']) {
                    continue;
                }

                $proposals[$sheetId] = $proposal->with($proposal->mapping, $proposal->options->withLookupSheet($listId));
                $proposals[$listId] = $proposals[$listId]->with($proposals[$listId]->mapping->withKind(DataImportSheetKind::PuzzleList, ColumnCertainty::Certain), $proposals[$listId]->options);

                break;
            }
        }

        return $proposals;
    }

    /**
     * How the sheet's times and dates are read (ImportColumnDecisions::settle()) - and, where a layout was applied,
     * its answers given to the questions that are left.
     *
     * @param callable(): iterable<int, array<int, array<string, mixed>>> $rows the table's rows from its first one,
     *        each time it is called
     *
     * @return array{ImportSheetOptions, list<ImportQuestion>}
     */
    public function settle(ImportMapping $mapping, ImportSheetOptions $options, string $sheetTitle, callable $rows, null|ImportLayoutContent $layout): array
    {
        [$options, $questions] = $this->decisions->settle($mapping, $options, $sheetTitle, $rows());

        for ($pass = 0; $layout !== null && $questions !== [] && $pass < self::ANSWER_PASSES; $pass++) {
            $answers = self::answers($questions, $layout, self::keysByColumn($mapping));

            if ($answers === []) {
                break;
            }

            // An answer may open the next question (what "Jan 23" means decides which orders are left)
            $options = $this->decisions->answered($mapping->withQuestions($questions), $options, $answers);
            [$options, $questions] = $this->decisions->settle($mapping, $options, $sheetTitle, $rows());
        }

        return [$options, $questions];
    }

    /**
     * The layout's answers to the questions a sheet asks. Only for the very column the answer was given for (a time
     * column the layout never knew is asked about like anybody's), only an answer the question offers (the sheet's
     * rows still allow that reading), and never one the rows point away from: a remembered answer does not hide a
     * question the new sheet's own values contradict.
     *
     * @param list<ImportQuestion> $questions
     * @param array<int, string> $keys the sheet's header keys by column (self::keysByColumn())
     *
     * @return array<string, string> question id => answer
     */
    public static function answers(array $questions, ImportLayoutContent $layout, array $keys): array
    {
        $answers = [];

        foreach ($questions as $question) {
            $key = $keys[$question->column] ?? null;
            $answer = match ($question->type) {
                ImportQuestionType::DurationShape => $question->shape === null || $key === null || $key !== $layout->durationsColumn
                    ? null
                    : ($layout->durations[$question->shape->value] ?? null)?->value,
                ImportQuestionType::DateOrder => $key === null || $key !== $layout->datesColumn ? null : $layout->dateOrder?->value,
                ImportQuestionType::ShortDates => $key === null || $key !== $layout->datesColumn ? null : $layout->shortDates?->value,
                // The year is the sheet's own; whether a spreadsheet turned typed dates around is the file's
                // ... and "are these pair results?" is asked of each sheet by its own title - a layout's word for
                // every row travels as the sheet's `solvingType`, by the rule above, and then nothing is asked
                // ... and "did we read your columns right?" is never asked of a sheet a layout was laid over
                ImportQuestionType::Year, ImportQuestionType::TypedDates, ImportQuestionType::SheetSolvingType, ImportQuestionType::CheckColumns => null,
            };

            if ($answer === null || in_array($answer, $question->choices, true) === false) {
                continue;
            }

            if ($question->suggested !== null && $question->suggested !== $answer) {
                continue;
            }

            $answers[$question->id()] = $answer;
        }

        return $answers;
    }

    /**
     * What a sheet's confirmed mapping and options are remembered as - null for a sheet that is not remembered: one
     * without a header row, and one the player left out (not puzzles, somebody else's).
     *
     * @param null|array{signature: string, title: string} $lookup
     */
    public static function contentOf(ImportMapping $mapping, ImportSheetOptions $options, null|array $lookup, string $sheetTitle = ''): null|ImportLayoutContent
    {
        $keys = self::keysByColumn($mapping);

        if ($keys === [] || $mapping->isImported() === false) {
            return null;
        }

        $columns = [];

        foreach ($mapping->columns as $column) {
            if (isset($keys[$column->column]) && $column->role === null) {
                $columns[$keys[$column->column]] = ['field' => $column->field, 'readyMade' => $column->readyMade];
            }
        }

        $durations = [];

        foreach ($options->durations as $shape => $setting) {
            if ($setting['by'] === ImportSheetOptions::BY_PLAYER) {
                $durations[$shape] = $setting['rule'];
            }
        }

        $time = $mapping->column(ImportField::Time);
        $date = $mapping->column(ImportField::SolvedOn);
        $dateOrder = ($options->dateOrder['by'] ?? null) === ImportSheetOptions::BY_PLAYER ? $options->dateOrder['order'] : null;
        $shortDates = ($options->shortDates['by'] ?? null) === ImportSheetOptions::BY_PLAYER ? $options->shortDates['meaning'] : null;
        $durationsColumn = $time === null ? null : $keys[$time->column] ?? null;
        $datesColumn = $date === null ? null : $keys[$date->column] ?? null;

        return new ImportLayoutContent(
            $mapping->kind,
            $mapping->rowMeaning,
            $columns,
            // An answer is kept with the column it was given for - without one it could only be guessed at later
            $durationsColumn === null ? [] : $durations,
            $datesColumn === null ? null : $dateOrder,
            $datesColumn === null ? null : $shortDates,
            $options->solvingType,
            $lookup,
            mb_substr($sheetTitle, 0, 2000),
            $durations === [] ? null : $durationsColumn,
            $dateOrder === null && $shortDates === null ? null : $datesColumn,
        );
    }

    /**
     * Whether the player's word differs from the mapping it was said about in what a layout keeps of the columns
     * and of the sheet.
     */
    public static function differs(ImportMapping $before, ImportMapping $after): bool
    {
        $facts = static fn (ImportMapping $mapping): array => [
            $mapping->kind->value,
            $mapping->rowMeaning->value,
            array_map(static fn (MappedColumn $column): array => [$column->column, $column->field?->value, $column->readyMade?->value], $mapping->columns),
        ];

        return $facts($before) !== $facts($after);
    }

    private static function singleSlot(MappedColumn $column): null|string
    {
        $slot = $column->slot();

        return $slot !== null && ($column->role !== null || $column->isSingleValued()) ? $slot : null;
    }
}
