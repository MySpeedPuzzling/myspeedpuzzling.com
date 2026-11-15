<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportRowMeaning;
use SpeedPuzzling\Web\Value\DataImportSheetKind;

/**
 * What the import takes a sheet for - `data_import_sheet.mapping` (docs/features/data-import/columns.md "The
 * mapping"): the tables found on it and which one is its data, what each column of that table is and how sure we
 * are, what the sheet holds, what a row is, and what must be asked before its rows can be parsed.
 *
 * Proposed by the import (ImportSheetAnalyser), changed only by the player (ConfirmImportMapping). Every change is a
 * new `mapping_version` of the sheet; rows are parsed for one version.
 */
final readonly class ImportMapping
{
    /** The shape of the stored JSON - a change that an older reader could not take is a new number */
    public const int SHAPE = 1;

    /**
     * @param list<DataRegion> $regions every table found, the largest first
     * @param int $region which of them is the sheet's data
     * @param list<MappedColumn> $columns the columns of that table, left to right
     * @param list<ImportQuestion> $questions
     */
    public function __construct(
        public DataImportSheetKind $kind,
        public ColumnCertainty $kindCertainty,
        public DataImportRowMeaning $rowMeaning,
        public array $regions,
        public int $region,
        public array $columns,
        public array $questions = [],
        /** A known export's id (KnownExportLayout) - its columns need no mapping */
        public null|string $layout = null,
        /** A layout the player saved earlier was applied (docs/features/data-import/layouts.md) - the screen says so */
        public null|RememberedLayout $remembered = null,
    ) {
    }

    /**
     * The sheet's data - null for a sheet nothing was found on.
     */
    public function table(): null|DataRegion
    {
        return $this->regions[$this->region] ?? null;
    }

    public function column(ImportField $field): null|MappedColumn
    {
        foreach ($this->columns as $column) {
            if ($column->field === $field) {
                return $column;
            }
        }

        return null;
    }

    public function has(ImportField $field): bool
    {
        return $this->column($field) !== null;
    }

    public function columnWithRole(ImportColumnRole $role): null|MappedColumn
    {
        foreach ($this->columns as $column) {
            if ($column->role === $role) {
                return $column;
            }
        }

        return null;
    }

    public function needsAnAnswer(): bool
    {
        return $this->questions !== [];
    }

    /**
     * Whether its rows are data of the import at all.
     */
    public function isImported(): bool
    {
        return $this->kind !== DataImportSheetKind::NotPuzzles
            && $this->kind !== DataImportSheetKind::SomebodyElse
            && $this->table() !== null;
    }

    /**
     * @param list<ImportQuestion> $questions
     */
    public function withQuestions(array $questions): self
    {
        return new self($this->kind, $this->kindCertainty, $this->rowMeaning, $this->regions, $this->region, $this->columns, $questions, $this->layout, $this->remembered);
    }

    public function withKind(DataImportSheetKind $kind, ColumnCertainty $certainty): self
    {
        return new self($kind, $certainty, $this->rowMeaning, $this->regions, $this->region, $this->columns, $this->questions, $this->layout, $this->remembered);
    }

    public function withRemembered(null|RememberedLayout $remembered): self
    {
        return new self($this->kind, $this->kindCertainty, $this->rowMeaning, $this->regions, $this->region, $this->columns, $this->questions, $this->layout, $remembered);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $stored = [
            'shape' => self::SHAPE,
            'layout' => $this->layout,
            'kind' => $this->kind->value,
            'kindCertainty' => $this->kindCertainty->value,
            'rowMeaning' => $this->rowMeaning->value,
            'region' => $this->region,
            'regions' => array_map(static fn (DataRegion $region): array => $region->toArray(), $this->regions),
            'columns' => array_map(static fn (MappedColumn $column): array => $column->toArray(), $this->columns),
            'questions' => array_map(static fn (ImportQuestion $question): array => $question->toArray(), $this->questions),
        ];

        // Only where a layout was applied: every other stored mapping keeps the shape it always had
        if ($this->remembered !== null) {
            $stored['remembered'] = $this->remembered->toArray();
        }

        return $stored;
    }

    /**
     * A stored mapping - or one a player sent: everything is checked, nothing is trusted.
     *
     * @param array<mixed> $data
     *
     * @throws InvalidImportMapping
     */
    public static function fromArray(array $data): self
    {
        if (($data['shape'] ?? null) !== self::SHAPE) {
            throw new InvalidImportMapping('The mapping has a shape this version does not know.');
        }

        $regions = array_map(DataRegion::fromArray(...), StoredShape::items($data, 'regions', 200));
        $columns = array_map(MappedColumn::fromArray(...), StoredShape::items($data, 'columns', DataImportLimits::MAX_REGION_COLUMNS));
        $questions = array_map(ImportQuestion::fromArray(...), StoredShape::items($data, 'questions', 20));
        $layout = StoredShape::nullableString($data, 'layout', 60);
        $remembered = $data['remembered'] ?? null;

        if ($remembered !== null && is_array($remembered) === false) {
            throw new InvalidImportMapping('"remembered" must be an object.');
        }

        $mapping = new self(
            StoredShape::enum($data, 'kind', DataImportSheetKind::class),
            StoredShape::enum($data, 'kindCertainty', ColumnCertainty::class),
            StoredShape::enum($data, 'rowMeaning', DataImportRowMeaning::class),
            $regions,
            StoredShape::int($data, 'region', 0, 200),
            $columns,
            $questions,
            $layout,
            $remembered === null ? null : RememberedLayout::fromArray($remembered),
        );

        $table = $mapping->table();

        if ($table === null && ($regions !== [] || $columns !== [])) {
            throw new InvalidImportMapping('The mapping names a table the sheet does not have.');
        }

        if ($layout !== null && KnownExportLayout::byId($layout) === null) {
            throw new InvalidImportMapping('The mapping names an export we do not know.');
        }

        $seen = [];
        $slots = [];

        foreach ($columns as $column) {
            if ($table === null || $table->holdsColumn($column->column) === false || isset($seen[$column->column])) {
                throw new InvalidImportMapping('A column is outside the table, or named twice.');
            }

            $seen[$column->column] = true;
            $slot = $column->slot();

            if ($slot !== null && ($column->role !== null || $column->isSingleValued())) {
                if (isset($slots[$slot])) {
                    throw new InvalidImportMapping(sprintf('Two columns can not both be "%s".', $slot));
                }

                $slots[$slot] = true;
            }
        }

        return $mapping;
    }
}
