<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class BrandDirectoryEntry
{
    /**
     * Brands whose name does not start with a Latin letter (digits, Cyrillic,
     * Japanese, symbols) are listed under this heading.
     */
    public const string OTHER_LETTER = '#';

    /**
     * @param string $letter A–Z (accents stripped) or OTHER_LETTER
     */
    public function __construct(
        public string $brandName,
        public string $slug,
        public string $letter,
        public int $puzzlesCount,
        public int $solvesCount,
    ) {
    }
}
