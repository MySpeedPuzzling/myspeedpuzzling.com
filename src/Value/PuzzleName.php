<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One other name of a puzzle - the title of another box of it, in the language of that box when it is known.
 */
readonly final class PuzzleName
{
    public function __construct(
        public string $name,
        // A normalised BCP 47 tag (LanguageTag), null = not known
        public null|string $language,
    ) {
    }
}
