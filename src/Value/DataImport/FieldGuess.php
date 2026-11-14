<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use SpeedPuzzling\Web\Value\StandardCustomField;

/**
 * One guess of HeaderMatcher at what a column is, from its header alone.
 */
final readonly class FieldGuess
{
    /** The header is one of the field's words */
    public const int EXACT = 100;

    /** A header that only holds one of the field's words scores below this */
    public const int CONTAINED_BELOW = 96;

    public function __construct(
        public ImportField $field,
        /** Which ready-made field, when $field is ImportField::ReadyMadeField */
        public null|StandardCustomField $readyMadeField,
        /** EXACT, or 50-95 for a header that holds a word of the field among others */
        public int $score,
        /**
         * The only guess the header alone may map a column by: it is exact, no other field has the same word, and the
         * word is not one that means several things ("Name", "Count", "Type", "Year", "Completed" …). Every other
         * guess is an offer - the values or the player decide.
         */
        public bool $sure = false,
        /** Time only: the unit the header names for its numbers ("Time (min)", "Hours") */
        public null|DurationUnit $unitHint = null,
    ) {
    }

    public function isExact(): bool
    {
        return $this->score === self::EXACT;
    }
}
