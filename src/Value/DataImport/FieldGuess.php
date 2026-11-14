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
    ) {
    }

    public function isExact(): bool
    {
        return $this->score === self::EXACT;
    }
}
