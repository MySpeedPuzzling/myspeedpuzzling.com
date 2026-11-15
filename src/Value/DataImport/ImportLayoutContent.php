<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;
use SpeedPuzzling\Web\Value\DataImportRowMeaning;
use SpeedPuzzling\Web\Value\DataImportSheetKind;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\StandardCustomField;

/**
 * What a remembered layout holds - `import_layout.content` (docs/features/data-import/layouts.md "What is
 * remembered"): what the sheet is, what a row is, what each column is **by its header** (never by its place alone: a
 * moved column still maps), and the answers that are a habit of whoever keeps the sheet - how a shape of time is
 * read, the order of day and month, what "Jan 23" means.
 *
 * Never here: the year of a sheet's dates (the sheet's own), where the table stands, the questions, sample values,
 * or the id of anything of an import.
 */
final readonly class ImportLayoutContent
{
    public const int SHAPE = 1;

    /**
     * @param array<string, array{field: null|ImportField, readyMade: null|StandardCustomField}> $columns by header key
     *        (ImportLayoutMatcher::headerKeys()) - a column with a role (a known export's row id …) is not here
     * @param array<string, DurationReadingRule> $durations DurationShape's value => the player's answer
     * @param null|array{signature: string, title: string} $lookup the sheet brand and pieces were looked up in
     */
    public function __construct(
        public DataImportSheetKind $kind,
        public DataImportRowMeaning $rowMeaning,
        public array $columns,
        public array $durations = [],
        public null|DateOrder $dateOrder = null,
        public null|ShortDateMeaning $shortDates = null,
        public null|PuzzlingType $solvingType = null,
        public null|array $lookup = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'shape' => self::SHAPE,
            'kind' => $this->kind->value,
            'rowMeaning' => $this->rowMeaning->value,
            'columns' => array_map(
                static fn (array $column): array => ['field' => $column['field']?->value, 'readyMade' => $column['readyMade']?->value],
                $this->columns,
            ),
            'durations' => array_map(static fn (DurationReadingRule $rule): string => $rule->value, $this->durations),
            'dateOrder' => $this->dateOrder?->value,
            'shortDates' => $this->shortDates?->value,
            'solvingType' => $this->solvingType?->value,
            'lookup' => $this->lookup,
        ];
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidImportMapping a stored layout this version can not read - it is then not applied
     */
    public static function fromArray(array $data): self
    {
        if (($data['shape'] ?? null) !== self::SHAPE) {
            throw new InvalidImportMapping('The layout has a shape this version does not know.');
        }

        $columns = [];
        $stored = $data['columns'] ?? [];
        $durations = [];
        $storedDurations = $data['durations'] ?? [];
        $lookup = $data['lookup'] ?? null;

        if (is_array($stored) === false || is_array($storedDurations) === false || ($lookup !== null && is_array($lookup) === false)) {
            throw new InvalidImportMapping('The layout is not the shape it must be.');
        }

        foreach ($stored as $key => $column) {
            if (is_array($column) === false) {
                throw new InvalidImportMapping('A column of the layout is not the shape it must be.');
            }

            $field = StoredShape::nullableEnum($column, 'field', ImportField::class);
            $readyMade = StoredShape::nullableEnum($column, 'readyMade', StandardCustomField::class);

            if (($field === ImportField::ReadyMadeField) !== ($readyMade !== null)) {
                throw new InvalidImportMapping('A ready-made field must be named, and only for a column that is one.');
            }

            $columns[(string) $key] = ['field' => $field, 'readyMade' => $readyMade];
        }

        foreach ($storedDurations as $shape => $rule) {
            if (is_string($shape) === false || DurationShape::tryFrom($shape) === null || is_string($rule) === false || DurationReadingRule::tryFrom($rule) === null) {
                throw new InvalidImportMapping('The layout names a reading of times we do not know.');
            }

            $durations[$shape] = DurationReadingRule::from($rule);
        }

        return new self(
            StoredShape::enum($data, 'kind', DataImportSheetKind::class),
            StoredShape::enum($data, 'rowMeaning', DataImportRowMeaning::class),
            $columns,
            $durations,
            StoredShape::nullableEnum($data, 'dateOrder', DateOrder::class),
            StoredShape::nullableEnum($data, 'shortDates', ShortDateMeaning::class),
            StoredShape::nullableEnum($data, 'solvingType', PuzzlingType::class),
            $lookup === null ? null : ['signature' => StoredShape::string($lookup, 'signature', 80), 'title' => StoredShape::string($lookup, 'title', 2000)],
        );
    }
}
