<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Component\Validator\Constraints\Length;

/**
 * One row of "Other names" in the names editor (PuzzleNameType). A row without a name is dropped on submit.
 */
final class PuzzleNameFormData
{
    public function __construct(
        #[Length(max: PuzzleNames::MAX_NAME_LENGTH, maxMessage: 'puzzle_names.name_too_long')]
        public null|string $name = null,
        // A BCP 47 tag from PuzzleNameLanguageChoices, null = language not known
        public null|string $language = null,
    ) {
    }
}
