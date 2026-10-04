<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;

/**
 * One other name of a puzzle - the title of another box of it - with the BCP 47 language of that box
 * (`cs`, `de`, `pt-BR`, `zh-Hant`), null when it is not known. See docs/features/puzzle-names/README.md.
 */
final class PuzzleNameResponse
{
    public function __construct(
        public string $name,
        public null|string $language,
    ) {
    }

    /**
     * @return list<self> in the puzzle's order
     */
    public static function listFrom(PuzzleNames $names): array
    {
        return array_map(
            static fn (PuzzleName $name): self => new self($name->name, $name->language),
            $names->all(),
        );
    }
}
