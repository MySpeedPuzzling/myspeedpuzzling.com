<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One field of one puzzle where the box says something the catalogue does not (BoxTextMismatchDetector).
 */
readonly final class DetectedBoxTextMismatch
{
    public const int MAX_VALUE_KEY_LENGTH = 255;

    /**
     * @param list<string> $boxValues the box's values for the field, canonical and sorted
     * @param list<string> $evidence the printed text they were read from, where the analysis keeps it
     */
    public function __construct(
        public string $puzzleId,
        public BoxTextField $field,
        public array $boxValues,
        public null|string $catalogueValue,
        public array $evidence,
    ) {
    }

    /**
     * What a dismissal remembers (box_text_mismatch_decision) - the same box values give the same key.
     */
    public function valueKey(): string
    {
        return self::valueKeyOf($this->boxValues);
    }

    /**
     * @param list<string> $boxValues
     */
    public static function valueKeyOf(array $boxValues): string
    {
        return mb_substr(implode(', ', $boxValues), 0, self::MAX_VALUE_KEY_LENGTH);
    }
}
