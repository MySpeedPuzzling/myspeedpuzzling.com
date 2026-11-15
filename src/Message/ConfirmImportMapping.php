<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The player's word on one sheet of their import (docs/features/data-import/columns.md "Confirming"): a changed
 * mapping, answers to the sheet's questions, what holds for the whole sheet, what words of its columns mean - any of
 * them. Validated, stored as the sheet's next `mapping_version`, and the sheet - only that sheet, and the sheets
 * that look puzzles up in it - is parsed again.
 *
 * The message and its handler only: the screen that sends it is the next stage's.
 */
readonly final class ConfirmImportMapping
{
    /**
     * @param int $mappingVersion the version the player looked at - a newer one in the database refuses the change
     * @param null|array<mixed> $mapping the whole mapping (ImportMapping::toArray()'s shape) - null: unchanged
     * @param array<mixed> $answers question id => the chosen value
     * @param null|array<mixed> $sheetWide `solvingType`, `lookupSheetId`, `collectionLabel` - only the keys that
     *        are there change; null: unchanged
     * @param null|array<mixed> $valueAnswers column number => word => ImportWordMeaning's value; replaces the
     *        sheet's - null: unchanged
     */
    public function __construct(
        public string $importId,
        public string $playerId,
        public string $sheetId,
        public int $mappingVersion,
        public null|array $mapping = null,
        public array $answers = [],
        public null|array $sheetWide = null,
        public null|array $valueAnswers = null,
    ) {
    }
}
