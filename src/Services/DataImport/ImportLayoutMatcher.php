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
use SpeedPuzzling\Web\Value\DataImport\RememberedLayout;
use SpeedPuzzling\Web\Value\DataImport\SavedImportLayout;
use SpeedPuzzling\Web\Value\DataImportSheetKind;

/**
 * Knows a sheet's layout again and applies what the player set up for it last time
 * (docs/features/data-import/layouts.md). Pure - no database.
 *
 * - **The signature** of a sheet is its header row as it is compared: every header folded (HeaderMatcher::words()),
 *   a header that stands there twice numbered, left to right. A sheet without a header row has none - and is never
 *   remembered.
 * - **The same headers** (in any order): every column is as the player set it up, the sheet is what they said it is.
 * - **At least SHARE_TO_APPLY of the headers in common:** the columns both have are as the player set them up, the
 *   others are proposed as usual, and what the sheet is is worked out again from all of them.
 * - **Answers** (how a shape of time is read, day or month first, what "Jan 23" means) answer only a question the
 *   new sheet really asks, only with a reading its rows still allow, and never against what its rows point to - then
 *   the question is asked again.
 */
final readonly class ImportLayoutMatcher
{
    public const float SHARE_TO_APPLY = 0.8;

    private const string SIGNATURE_VERSION = 'L1:';
    private const int ANSWER_PASSES = 3;

    public function __construct(
        private SheetKindGuesser $kindGuesser,
        private ImportColumnDecisions $decisions,
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
     * The proposal with the fitting layout applied - or as it was, when none fits.
     *
     * @param list<SavedImportLayout> $layouts
     */
    public function apply(string $sheetTitle, ImportSheetProposal $proposal, array $layouts): ImportSheetProposal
    {
        $match = self::matching($proposal->mapping, $layouts);

        if ($match === null) {
            return $proposal;
        }

        [$layout, $whole] = $match;
        $mapping = $proposal->mapping;
        $content = $layout->content;
        $keys = self::keysByColumn($mapping);
        $columns = [];
        $slots = [];
        $remembered = [];

        foreach ($mapping->columns as $index => $column) {
            $known = $content->columns[$keys[$column->column] ?? ''] ?? null;

            // A role (the source's own row id …) is a known export's to give, never a layout's
            if ($known !== null && $column->role === null) {
                $column = new MappedColumn(
                    $column->column,
                    $column->header,
                    $known['field'],
                    ColumnCertainty::Certain,
                    MappedColumn::BY_REMEMBERED,
                    $known['readyMade'],
                    null,
                    null,
                    $known['field'] === ImportField::Time ? $column->unit : null,
                );
                $remembered[$index] = true;
            }

            if (isset($remembered[$index]) || $column->role !== null) {
                $slot = self::singleSlot($column);

                // Two remembered columns on one field can not be: the later one is left as it was proposed
                if ($slot !== null && isset($slots[$slot])) {
                    $column = $mapping->columns[$index];
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

        if ($whole) {
            // "The puzzle list the other sheets point to" is found across the workbook each time - by itself the
            // sheet is a list of owned puzzles
            $kind = $content->kind === DataImportSheetKind::PuzzleList ? DataImportSheetKind::Owned : $content->kind;
            $certainty = ColumnCertainty::Certain;
            $rowMeaning = $content->rowMeaning;
        } else {
            [$kind, $certainty, $rowMeaning] = $this->kindGuesser->guess($sheetTitle, $columns, $mapping->layout === null ? null : KnownExportLayout::byId($mapping->layout));
        }

        $applied = new ImportMapping(
            $kind,
            $certainty,
            $rowMeaning,
            $mapping->regions,
            $mapping->region,
            $columns,
            [],
            $mapping->layout,
            new RememberedLayout($whole ? RememberedLayout::WHOLE : RememberedLayout::PARTLY, $layout->id, mb_substr($layout->name, 0, 200)),
        );

        try {
            // What would be refused as a stored mapping is not applied: the sheet keeps its own proposal
            ImportMapping::fromArray($applied->toArray());
        } catch (InvalidImportMapping) {
            return $proposal;
        }

        $options = $content->solvingType === null ? $proposal->options : $proposal->options->withSolvingType($content->solvingType);

        return new ImportSheetProposal($applied, $options, $proposal->valueAnswers, $proposal->headerSignature);
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
            $answers = self::answers($questions, $layout);

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
     * The layout's answers to the questions a sheet asks. Only an answer the question offers (the sheet's rows still
     * allow that reading), and never one the rows point away from: a remembered answer does not hide a question the
     * new sheet's own values contradict.
     *
     * @param list<ImportQuestion> $questions
     *
     * @return array<string, string> question id => answer
     */
    public static function answers(array $questions, ImportLayoutContent $layout): array
    {
        $answers = [];

        foreach ($questions as $question) {
            $answer = match ($question->type) {
                ImportQuestionType::DurationShape => $question->shape === null ? null : ($layout->durations[$question->shape->value] ?? null)?->value,
                ImportQuestionType::DateOrder => $layout->dateOrder?->value,
                ImportQuestionType::ShortDates => $layout->shortDates?->value,
                // The year is the sheet's own; whether a spreadsheet turned typed dates around is the file's
                ImportQuestionType::Year, ImportQuestionType::TypedDates => null,
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
    public static function contentOf(ImportMapping $mapping, ImportSheetOptions $options, null|array $lookup): null|ImportLayoutContent
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

        return new ImportLayoutContent(
            $mapping->kind,
            $mapping->rowMeaning,
            $columns,
            $durations,
            ($options->dateOrder['by'] ?? null) === ImportSheetOptions::BY_PLAYER ? $options->dateOrder['order'] : null,
            ($options->shortDates['by'] ?? null) === ImportSheetOptions::BY_PLAYER ? $options->shortDates['meaning'] : null,
            $options->solvingType,
            $lookup,
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
