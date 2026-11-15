<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\MyData;

/**
 * What became of one cell of a result saved in place (SaveMyDataResultCell).
 */
readonly final class MyDataCellSave
{
    public const string SAVED = 'saved';
    public const string UNCHANGED = 'unchanged';
    /** The result no longer holds what the cell showed - nothing was saved */
    public const string CHANGED_MEANWHILE = 'changed';
    /** The value is no value of the field at all */
    public const string INVALID = 'invalid';
    /** The edit form's rules refused it - `outcome` says why */
    public const string REFUSED = 'refused';
    /** Not the player's to edit (an imported event result), or no such field on this result */
    public const string READ_ONLY = 'read_only';
    /** Somebody else registered is on the result: edited through its row sheet, one save = one notification */
    public const string IN_SHEET_ONLY = 'in_sheet_only';

    public function __construct(
        public string $status,
        public null|MyDataResultEditOutcome $outcome = null,
    ) {
    }
}
