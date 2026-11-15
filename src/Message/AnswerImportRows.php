<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The player's word about single rows of their import (docs/features/data-import/pairing-screens.md "To fix", "The
 * row sheet"): a value typed for a field - of one row, or of every row that has the same unclear value - or "leave
 * these rows out". The rows keep the answer (`data_import_row.answers`) and are read again at once, under the
 * import's lock; the import's puzzles are built and paired again afterwards.
 *
 * All of it or none: a value that can not be read for sure (ImportRowAnswerRefused, with its field), a row of
 * another import, a sheet that is just being read again or a row somebody changed meanwhile
 * (ImportRowsChangedMeanwhile) refuse the message before anything is written. The handler answers how many rows it
 * changed.
 */
readonly final class AnswerImportRows
{
    /**
     * @param list<string> $rowIds at most ImportRowAnswers::MAX_ROWS
     * @param array<string, string> $answers ImportField's value => the text as typed; an empty text takes the
     *        row's answer for that field back (the cell's own value is read again)
     * @param null|bool $leaveOut true: the rows are left out, false: taken in again, null: as they are
     * @param null|string $onlyWithProblem ImportRowAnswers::problemKey() - only rows that still have this very
     *        problem are changed (one answer for a group of "To fix": a row answered meanwhile is passed over)
     * @param null|string $seenStamp DataImportRow::stamp() of the one row as its page showed it - another one in
     *        the database refuses the change
     */
    public function __construct(
        public string $importId,
        public string $playerId,
        public array $rowIds,
        public array $answers = [],
        public null|bool $leaveOut = null,
        public null|string $onlyWithProblem = null,
        public null|string $seenStamp = null,
    ) {
    }
}
