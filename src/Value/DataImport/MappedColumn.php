<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\StandardCustomField;

/**
 * One column of a sheet's table and what the import takes it for (docs/features/data-import/columns.md "The mapping").
 */
final readonly class MappedColumn
{
    /** Why a column is what it is - stored, never rename one */
    public const string BY_HEADER = 'header';
    public const string BY_VALUES = 'values';
    public const string BY_HEADER_AND_VALUES = 'header_and_values';
    /** A column of formulas MySpeedPuzzling works out itself */
    public const string BY_FORMULA = 'formula';
    public const string BY_KNOWN_EXPORT = 'known_export';
    /** Another column has the field: this one is the weaker of the two */
    public const string BY_DUPLICATE = 'duplicate';
    public const string BY_PLAYER = 'player';
    public const string BY_NOTHING = 'nothing';

    public const array REASONS = [
        self::BY_HEADER, self::BY_VALUES, self::BY_HEADER_AND_VALUES, self::BY_FORMULA, self::BY_KNOWN_EXPORT,
        self::BY_DUPLICATE, self::BY_PLAYER, self::BY_NOTHING,
    ];

    public function __construct(
        /** 1 = A */
        public int $column,
        /** The header as the sheet has it - '' for a column without one ("Column N") */
        public string $header,
        /** null: not imported (unknown, or "Don't import") */
        public null|ImportField $field,
        public ColumnCertainty $certainty,
        public string $reason,
        /** Which ready-made field, when $field is ImportField::ReadyMadeField */
        public null|StandardCustomField $readyMade = null,
        public null|ImportColumnRole $role = null,
        /** What the column would be if nothing stood in the way - for a column left unknown (a duplicate, a weak guess) */
        public null|ImportField $offered = null,
        /** Time only: the unit its header names ("Time (min)") */
        public null|DurationUnit $unit = null,
    ) {
    }

    public function is(ImportField $field): bool
    {
        return $this->field === $field;
    }

    public function asUnknown(string $reason): self
    {
        return new self($this->column, $this->header, null, ColumnCertainty::Unknown, $reason, null, null, $this->field, $this->unit);
    }

    /**
     * Whether two columns may be this at once: a row has one name, one time, one date - but several people, several
     * collections, several formulas, several flags that say "not mine any more".
     */
    public function isSingleValued(): bool
    {
        return $this->field !== null && in_array($this->field, [
            ImportField::Computed,
            ImportField::Collection,
            ImportField::SolvedWith,
            ImportField::NotOwned,
        ], true) === false;
    }

    /**
     * What two columns must not share: the field - for a ready-made one, the field it is.
     */
    public function slot(): null|string
    {
        return match (true) {
            $this->role !== null => 'role:' . $this->role->value,
            $this->field === null => null,
            $this->field === ImportField::ReadyMadeField => 'custom:' . ($this->readyMade->value ?? ''),
            default => $this->field->value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'column' => $this->column,
            'header' => $this->header,
            'field' => $this->field?->value,
            'readyMade' => $this->readyMade?->value,
            'role' => $this->role?->value,
            'certainty' => $this->certainty->value,
            'reason' => $this->reason,
            'offered' => $this->offered?->value,
            'unit' => $this->unit?->value,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $column = new self(
            StoredShape::int($data, 'column', 1, DataImportLimits::MAX_COLUMNS),
            StoredShape::string($data, 'header', 2000),
            StoredShape::nullableEnum($data, 'field', ImportField::class),
            StoredShape::enum($data, 'certainty', ColumnCertainty::class),
            StoredShape::string($data, 'reason', 30),
            StoredShape::nullableEnum($data, 'readyMade', StandardCustomField::class),
            StoredShape::nullableEnum($data, 'role', ImportColumnRole::class),
            StoredShape::nullableEnum($data, 'offered', ImportField::class),
            StoredShape::nullableEnum($data, 'unit', DurationUnit::class),
        );

        if (in_array($column->reason, self::REASONS, true) === false) {
            throw new InvalidImportMapping('A column\'s reason is not one we know.');
        }

        if (($column->field === ImportField::ReadyMadeField) !== ($column->readyMade !== null)) {
            throw new InvalidImportMapping('A ready-made field must be named, and only for a column that is one.');
        }

        if ($column->role !== null && $column->field !== null) {
            throw new InvalidImportMapping('A column is a field or has a role, not both.');
        }

        return $column;
    }
}
