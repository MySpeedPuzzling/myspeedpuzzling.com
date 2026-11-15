<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One line of "What will be added" (GetImportAddScreen::readyRows(), docs/features/data-import/guided-flow.md §7):
 * a row an "Add" would take now, with what it would make - or a row the player chose not to import, shown in its
 * place in the file so that putting it back is one tap. A row that is not imported keeps nothing of what it said
 * but its cells: `fileSays` is what its name, time and date cells hold, as the player wrote them (escaped on the
 * page, never in a URL).
 */
readonly final class ImportAddListRow
{
    /**
     * @param list<string> $fileSays only for a row that is not imported
     */
    public function __construct(
        public ImportAddRow $row,
        public string $sheetId,
        public string $sheetTitle,
        public int $rowNumber,
        /** The player said "Don't import" about the row (AnswerImportRows) - it is not added until put back */
        public bool $notImported,
        public bool $newResult = false,
        public bool $newOwned = false,
        public bool $newWish = false,
        /** Kept with the player's unlisted puzzles - nothing of it is public */
        public bool $unlisted = false,
        /** The same result as another row of the file: counted once */
        public bool $repeats = false,
        public array $fileSays = [],
    ) {
    }

    /** Taken by the "Add" and adding nothing: everything the row says is on the profile already */
    public function onProfileAlready(): bool
    {
        return $this->notImported === false && $this->unlisted === false && $this->repeats === false
            && $this->newResult === false && $this->newOwned === false && $this->newWish === false;
    }
}
