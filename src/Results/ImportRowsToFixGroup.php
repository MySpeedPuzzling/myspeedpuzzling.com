<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Rows of an import that need a look for the same reason: the same field, the same thing unclear, the same value as
 * the sheet has it (docs/features/data-import/pairing-screens.md "To fix"). One answer fixes them all.
 */
readonly final class ImportRowsToFixGroup
{
    public function __construct(
        /** ImportRowAnswers::problemKey() - what the group is named by in a form, never its value */
        public string $key,
        /** An ImportField's value - '' for a problem of no field */
        public string $field,
        public string $code,
        /** As the sheet has it - the player's own text */
        public string $value,
        public int $rows,
        public string $sampleRowId,
        /** The kind of input an answer gets (ImportRowAnswers::INPUTS) - null: the field can not be answered here */
        public null|string $input,
        /** What we would read, when every row of the group reads the same - offered as the answer, never taken by itself */
        public null|string $proposal,
    ) {
    }
}
