<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * How sure the import is what a column of a sheet is (docs/features/data-import/columns.md "Certainty").
 *
 * The values are stored with a sheet's mapping - never rename one.
 */
enum ColumnCertainty: string
{
    /** Header and values agree, or a known export says so: shown in the one line "Recognised: …" */
    case Certain = 'certain';
    /** A guess worth showing ticked, as a card the player looks at */
    case Probable = 'probable';
    /** Nothing to go by - or the weaker of two columns that wanted the same field: the player says what it is */
    case Unknown = 'unknown';

    public function rank(): int
    {
        return match ($this) {
            self::Certain => 2,
            self::Probable => 1,
            self::Unknown => 0,
        };
    }
}
