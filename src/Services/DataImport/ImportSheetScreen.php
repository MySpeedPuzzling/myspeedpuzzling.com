<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;
use SpeedPuzzling\Web\Results\ImportSheetDetail;
use SpeedPuzzling\Web\Services\DataImport\Reading\DurationReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\ImportDateReader;
use SpeedPuzzling\Web\Value\DataImport\CellInput;
use SpeedPuzzling\Web\Value\DataImport\ColumnCertainty;
use SpeedPuzzling\Web\Value\DataImport\DateOrder;
use SpeedPuzzling\Web\Value\DataImport\DurationReadingRule;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportQuestion;
use SpeedPuzzling\Web\Value\DataImport\ImportQuestionType;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;
use SpeedPuzzling\Web\Value\DataImport\MappedColumn;
use SpeedPuzzling\Web\Value\DataImport\ShortDateMeaning;
use SpeedPuzzling\Web\Value\DataImportRowMeaning;
use SpeedPuzzling\Web\Value\DataImportSheetKind;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\Spreadsheet\CellRange;
use SpeedPuzzling\Web\Value\StandardCustomField;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * The "Sheets and columns" screen of one sheet (docs/features/data-import/columns-screen.md), both ways:
 *
 * - view(): what its page shows of the sheet's mapping - the questions with their choices and how each choice reads
 *   the sheet's own examples, the columns (those we are sure about as lines, the others as cards) with sample values
 *   and what each is, what the sheet is and what a row is;
 * - submission(): what the page's form says, as the arguments of ConfirmImportMapping - checked first for what can
 *   be said at a field (a year that is none, two columns on one field); everything else is the handler's check.
 *
 * Pure: no database, no translator - texts are keys the template translates. Sample values and examples are the
 * player's own text: the template escapes them, and nothing of them ever goes into a URL.
 */
final readonly class ImportSheetScreen
{
    /** Rows the sample values of the columns are looked for in */
    public const int SAMPLE_ROWS = 12;
    private const int SAMPLES = 3;
    private const int SAMPLE_LENGTH = 60;

    /** The picker's value for "Don't import" */
    public const string NOT_IMPORTED = '';
    private const string READY_MADE_PREFIX = 'ready:';

    /** What a column can be, in the picker's order: the group's key => the fields */
    private const array FIELD_GROUPS = [
        'puzzle' => [ImportField::PuzzleName, ImportField::Brand, ImportField::Pieces, ImportField::Barcode, ImportField::BrandCode],
        'result' => [
            ImportField::SolvedOn, ImportField::Time, ImportField::SolvedWith, ImportField::SolvingType, ImportField::FirstTry,
            ImportField::AttemptNumber, ImportField::SolvedWithoutBox, ImportField::PublicComment,
        ],
        'ownership' => [ImportField::Status, ImportField::Owned, ImportField::NotOwned, ImportField::Collection, ImportField::AlreadyOnMySpeedPuzzling],
    ];

    public function __construct(
        private DurationReader $durationReader,
        private ImportDateReader $dateReader,
    ) {
    }

    /**
     * @param list<array<int, array<string, mixed>>> $firstCells the cells of the table's first rows (by column)
     * @param null|InputBag<string> $submitted a refused form: its choices are shown again
     * @param array<string, string> $errors `form`, `question.<id>`, `column.<number>` => the error's key
     *
     * @return array<string, mixed> the template's variables
     */
    public function view(ImportSheetDetail $sheet, array $firstCells, null|InputBag $submitted = null, array $errors = []): array
    {
        $mapping = self::mappingOf($sheet);

        if ($mapping === null) {
            return ['mapping' => null, 'questions' => [], 'sure_columns' => [], 'open_columns' => [], 'field_groups' => [], 'errors' => $errors];
        }

        $options = self::optionsOf($sheet);
        $submittedColumns = $submitted?->all('columns') ?? [];
        $submittedAnswers = $submitted?->all('answers') ?? [];
        $sure = [];
        $open = [];

        foreach ($mapping->columns as $column) {
            $picked = $submittedColumns[$column->column] ?? null;
            $error = $errors['column.' . $column->column] ?? null;
            $value = is_string($picked) ? $picked : self::pickerValue($column);
            $card = [
                'column' => $column->column,
                'letter' => CellRange::columnLetters($column->column),
                'header' => $column->header,
                'samples' => self::samples($firstCells, $column->column),
                'value' => $value,
                'label' => self::labelKey($value),
                'offered' => $column->offered === null ? null : self::fieldKey($column->offered->value),
                'reason' => $column->reason,
                'role' => $column->role?->value,
                'certainty' => $column->certainty->value,
                'error' => $error,
            ];

            if ($column->certainty === ColumnCertainty::Certain || $column->role !== null) {
                $sure[] = $card;
            } else {
                $open[] = $card;
            }
        }

        $kind = $submitted?->getString('kind') ?? '';
        $rowMeaning = $submitted?->getString('row_meaning') ?? '';
        $solvingType = $submitted !== null && $submitted->has('solving_type') ? $submitted->getString('solving_type') : ($options->solvingType->value ?? '');

        return [
            'mapping' => $mapping,
            'questions' => array_map(
                fn (ImportQuestion $question): array => $this->question($question, $mapping, $submittedAnswers, $submitted?->getString('year_other') ?? '', $errors),
                $mapping->questions,
            ),
            'sure_columns' => $sure,
            'open_columns' => $open,
            'field_groups' => self::fieldGroups(),
            'kind' => $kind !== '' ? $kind : $mapping->kind->value,
            'kinds' => array_map(static fn (DataImportSheetKind $kind): string => $kind->value, DataImportSheetKind::cases()),
            'row_meaning' => $rowMeaning !== '' ? $rowMeaning : $mapping->rowMeaning->value,
            'row_meanings' => self::rowMeanings($mapping),
            'solving_type' => $solvingType,
            'solving_types' => array_map(static fn (PuzzlingType $type): string => $type->value, PuzzlingType::cases()),
            'layout' => $mapping->layout,
            'table' => $mapping->table(),
            'tables' => count($mapping->regions),
            'errors' => $errors,
        ];
    }

    /**
     * What the form says, as ConfirmImportMapping takes it.
     *
     * @param InputBag<string> $form
     *
     * @return array{mapping: null|array<mixed>, answers: array<string, string>, sheetWide: null|array<string, mixed>, errors: array<string, string>}
     */
    public function submission(ImportSheetDetail $sheet, InputBag $form): array
    {
        $current = self::mappingOf($sheet);
        $errors = [];

        if ($current === null) {
            return ['mapping' => null, 'answers' => [], 'sheetWide' => null, 'errors' => ['form' => 'refused']];
        }

        // What the sheet is and what a row is: whichever the player changed decides the other
        $kind = $form->getString('kind') === '' ? $current->kind : DataImportSheetKind::tryFrom($form->getString('kind'));
        $rowMeaning = $form->getString('row_meaning') === '' ? $current->rowMeaning : DataImportRowMeaning::tryFrom($form->getString('row_meaning'));

        if ($kind === null || $rowMeaning === null) {
            return ['mapping' => null, 'answers' => [], 'sheetWide' => null, 'errors' => ['form' => 'refused']];
        }

        if ($kind === $current->kind && $rowMeaning !== $current->rowMeaning) {
            $kind = match ($rowMeaning) {
                DataImportRowMeaning::Result, DataImportRowMeaning::Both => DataImportSheetKind::Results,
                DataImportRowMeaning::WishList => DataImportSheetKind::WishList,
                DataImportRowMeaning::Owned => $kind === DataImportSheetKind::PuzzleList ? $kind : DataImportSheetKind::Owned,
                DataImportRowMeaning::ByColumn => $kind,
            };
        } else {
            $rowMeaning = self::rowMeaningOf($kind, $rowMeaning);
        }

        $columns = [];
        $changed = [];
        $picked = $form->all('columns');

        foreach ($current->columns as $column) {
            $value = $picked[$column->column] ?? null;
            $stored = $column->toArray();

            if ($column->role === null && is_string($value) && $value !== self::pickerValue($column)) {
                [$field, $readyMade, $known] = self::picked($value);

                if ($known === false) {
                    $errors['column.' . $column->column] = 'unknown_field';
                } else {
                    $stored = [...$stored, 'field' => $field?->value, 'readyMade' => $readyMade?->value];
                    $changed[$column->column] = true;
                }
            }

            $columns[] = $stored;
        }

        // Never two columns on one field that a row has once (a name, a time, a date): said at the column the player
        // changed - at the later one when nobody or both did
        $holders = [];

        foreach ($columns as $stored) {
            try {
                $column = MappedColumn::fromArray($stored);
            } catch (InvalidImportMapping) {
                continue;
            }

            $slot = $column->slot();

            if ($slot === null || ($column->role === null && $column->isSingleValued() === false)) {
                continue;
            }

            if (isset($holders[$slot])) {
                $blamed = isset($changed[$holders[$slot]]) && isset($changed[$column->column]) === false ? $holders[$slot] : $column->column;
                $errors['column.' . $blamed] = 'duplicate';

                continue;
            }

            $holders[$slot] = $column->column;
        }

        $answers = [];

        foreach ($form->all('answers') as $id => $answer) {
            if (is_string($answer) === false || $answer === '') {
                continue;
            }

            if ($answer === 'other') {
                $answer = trim($form->getString('year_other'));

                if (preg_match('/^(19[5-9]\d|20\d\d|2100)$/', $answer) !== 1) {
                    $errors['question.' . $id] = 'year';

                    continue;
                }
            }

            $answers[(string) $id] = $answer;
        }

        $sheetWide = null;

        if ($form->has('solving_type')) {
            $solvingType = $form->getString('solving_type');
            $options = self::optionsOf($sheet);

            if ($solvingType !== ($options->solvingType->value ?? '')) {
                $sheetWide = ['solvingType' => $solvingType === '' ? null : $solvingType];
            }
        }

        // "A column says it for each row" only where a column does - the page never offers it otherwise
        $hasStatus = array_filter($columns, static fn (array $stored): bool => ($stored['field'] ?? null) === ImportField::Status->value) !== [];

        if ($rowMeaning === DataImportRowMeaning::ByColumn && $current->rowMeaning !== DataImportRowMeaning::ByColumn && $hasStatus === false) {
            $errors['form'] = 'refused';
        }

        $mappingChanged = $changed !== [] || $kind !== $current->kind || $rowMeaning !== $current->rowMeaning;

        return [
            'mapping' => $mappingChanged ? [...$current->toArray(), 'kind' => $kind->value, 'rowMeaning' => $rowMeaning->value, 'columns' => $columns] : null,
            'answers' => $answers,
            'sheetWide' => $sheetWide,
            'errors' => $errors,
        ];
    }

    public static function mappingOf(ImportSheetDetail $sheet): null|ImportMapping
    {
        if ($sheet->mappingVersion === 0 || $sheet->mapping === null) {
            return null;
        }

        try {
            return ImportMapping::fromArray($sheet->mapping);
        } catch (InvalidImportMapping) {
            return null;
        }
    }

    /**
     * What a row of a sheet of that kind is, keeping what the sheet said when it goes with the kind.
     */
    private static function rowMeaningOf(DataImportSheetKind $kind, DataImportRowMeaning $said): DataImportRowMeaning
    {
        return match ($kind) {
            DataImportSheetKind::Results => in_array($said, [DataImportRowMeaning::Result, DataImportRowMeaning::Both, DataImportRowMeaning::ByColumn], true) ? $said : DataImportRowMeaning::Result,
            DataImportSheetKind::Owned, DataImportSheetKind::PuzzleList => in_array($said, [DataImportRowMeaning::Owned, DataImportRowMeaning::ByColumn], true) ? $said : DataImportRowMeaning::Owned,
            DataImportSheetKind::WishList => DataImportRowMeaning::WishList,
            DataImportSheetKind::NotPuzzles, DataImportSheetKind::SomebodyElse => $said,
        };
    }

    /**
     * @return list<string>
     */
    private static function rowMeanings(ImportMapping $mapping): array
    {
        $meanings = [DataImportRowMeaning::Result, DataImportRowMeaning::Both, DataImportRowMeaning::Owned, DataImportRowMeaning::WishList];

        // "A column says it per row" only where a column can: a status, a "Completed?" tick
        if ($mapping->rowMeaning === DataImportRowMeaning::ByColumn || $mapping->has(ImportField::Status)) {
            $meanings[] = DataImportRowMeaning::ByColumn;
        }

        return array_map(static fn (DataImportRowMeaning $meaning): string => $meaning->value, $meanings);
    }

    private static function optionsOf(ImportSheetDetail $sheet): ImportSheetOptions
    {
        try {
            return ImportSheetOptions::fromArray($sheet->options);
        } catch (InvalidImportMapping) {
            return new ImportSheetOptions();
        }
    }

    /**
     * @return array<string, list<array{value: string, label: string}>> the group's key => its choices (label = a
     *         translation key)
     */
    private static function fieldGroups(): array
    {
        $groups = [];

        foreach (self::FIELD_GROUPS as $group => $fields) {
            $groups[$group] = array_map(static fn (ImportField $field): array => ['value' => $field->value, 'label' => self::fieldKey($field->value)], $fields);
        }

        $groups['my_fields'] = array_map(
            static fn (StandardCustomField $field): array => ['value' => self::READY_MADE_PREFIX . $field->value, 'label' => $field->labelKey()],
            StandardCustomField::cases(),
        );
        $groups['not_imported'] = [
            ['value' => self::NOT_IMPORTED, 'label' => 'data_import.columns.field.none'],
            ['value' => ImportField::Computed->value, 'label' => self::fieldKey(ImportField::Computed->value)],
        ];

        return $groups;
    }

    private static function fieldKey(string $field): string
    {
        return 'data_import.columns.field.' . $field;
    }

    /**
     * The translation key of what a picker's value says.
     */
    private static function labelKey(string $value): string
    {
        [$field, $readyMade] = self::picked($value);

        return match (true) {
            $readyMade !== null => $readyMade->labelKey(),
            $field !== null => self::fieldKey($field->value),
            default => 'data_import.columns.field.none',
        };
    }

    private static function pickerValue(MappedColumn $column): string
    {
        return match (true) {
            $column->field === null => self::NOT_IMPORTED,
            $column->field === ImportField::ReadyMadeField => self::READY_MADE_PREFIX . ($column->readyMade->value ?? ''),
            default => $column->field->value,
        };
    }

    /**
     * @return array{null|ImportField, null|StandardCustomField, bool} the field, the ready-made field, whether the
     *         value is one of the picker's
     */
    private static function picked(string $value): array
    {
        if ($value === self::NOT_IMPORTED) {
            return [null, null, true];
        }

        if (str_starts_with($value, self::READY_MADE_PREFIX)) {
            $readyMade = StandardCustomField::tryFrom(substr($value, strlen(self::READY_MADE_PREFIX)));

            return [$readyMade === null ? null : ImportField::ReadyMadeField, $readyMade, $readyMade !== null];
        }

        $field = ImportField::tryFrom($value);

        return [$field, null, $field !== null && $field !== ImportField::ReadyMadeField];
    }

    /**
     * @param list<array<int, array<string, mixed>>> $firstCells
     *
     * @return list<string>
     */
    private static function samples(array $firstCells, int $column): array
    {
        $samples = [];

        foreach ($firstCells as $cells) {
            $shown = trim(StoredCells::shown($cells[$column] ?? null));

            if ($shown === '' || in_array($shown, $samples, true)) {
                continue;
            }

            $samples[] = mb_strlen($shown) > self::SAMPLE_LENGTH ? mb_substr($shown, 0, self::SAMPLE_LENGTH) . '…' : $shown;

            if (count($samples) === self::SAMPLES) {
                break;
            }
        }

        return $samples;
    }

    /**
     * @param array<mixed> $submittedAnswers
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private function question(ImportQuestion $question, ImportMapping $mapping, array $submittedAnswers, string $yearOther, array $errors): array
    {
        $header = '';

        foreach ($mapping->columns as $column) {
            if ($column->column === $question->column) {
                $header = $column->header;
            }
        }

        $selected = $submittedAnswers[$question->id()] ?? null;

        return [
            'id' => $question->id(),
            'type' => $question->type->value,
            'shape' => $question->shape?->value,
            'header' => $header,
            'letter' => CellRange::columnLetters($question->column),
            'examples' => $question->examples,
            'rows' => $question->rows,
            'choices' => array_map(fn (string $choice): array => $this->choice($question, $choice), $question->choices),
            'selected' => is_string($selected) ? $selected : null,
            'year_other' => $yearOther,
            'error' => $errors['question.' . $question->id()] ?? null,
        ];
    }

    /**
     * One answer of a question, and how it reads the sheet's own examples - so the player picks by what they see,
     * not by a rule's name.
     *
     * @return array{value: string, label: null|string, readings: list<array{example: string, time: null|string, date: null|\DateTimeImmutable, month: bool}>}
     */
    private function choice(ImportQuestion $question, string $choice): array
    {
        $readings = [];

        foreach ($question->examples as $example) {
            $reading = ['example' => $example, 'time' => null, 'date' => null, 'month' => false];
            $cell = CellInput::text($example);

            if ($question->type === ImportQuestionType::DurationShape) {
                $rule = DurationReadingRule::tryFrom($choice);
                $seconds = $rule === null ? null : $this->durationReader->read($cell)->secondsBy($rule);
                $reading['time'] = $seconds === null ? null : sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
            }

            if ($question->type === ImportQuestionType::DateOrder) {
                $order = DateOrder::tryFrom($choice);
                $read = $this->dateReader->read($cell);
                $reading['date'] = $order === null || $read->allows($order) === false ? null : $read->inOrder($order)->date;
            }

            if ($question->type === ImportQuestionType::ShortDates) {
                $meaning = ShortDateMeaning::tryFrom($choice);
                $read = $meaning === null ? null : $this->dateReader->read($cell)->shortReading($meaning);
                $reading['date'] = $read?->date;
                $reading['month'] = $read !== null && $read->precision === DatePrecision::Month;
            }

            if ($reading['time'] !== null || $reading['date'] !== null) {
                $readings[] = $reading;
            }
        }

        return [
            'value' => $choice,
            // A year is its own label
            'label' => match ($question->type) {
                ImportQuestionType::DurationShape => 'data_import.columns.answer.duration.' . $choice,
                ImportQuestionType::DateOrder => 'data_import.columns.answer.date_order.' . $choice,
                ImportQuestionType::ShortDates => 'data_import.columns.answer.short_dates.' . $choice,
                ImportQuestionType::TypedDates => 'data_import.columns.answer.typed_dates.' . $choice,
                ImportQuestionType::Year => $choice === ImportQuestion::YEAR_AS_STORED ? 'data_import.columns.answer.year.stored' : null,
            },
            'readings' => $readings,
        ];
    }
}
