<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * One answer for a whole group of rows of the player's import (docs/features/data-import/group-answers.md): every
 * row that waits with one reason ($code - an unusual time, "the file says it is on MySpeedPuzzling") gets "add it
 * anyway" or is left out; with $beforeAdd every ready result whose time the verification finds unusual does. The
 * rows are never sent by a form: the server works them out, in bounded chunks (ProcessImportGroupAnswer).
 *
 * `$requestId` is the id the form chose for its press - the same form sent again answers the same group answer.
 * The handler answers its id and whether it is small enough to be gone through at once - or null: no row is in
 * the group (any more).
 */
readonly final class AnswerImportRowsGroup
{
    public function __construct(
        public string $requestId,
        public string $importId,
        public string $playerId,
        public string $code,
        /** DataImportGroupAnswer::ADD_ANYWAY or LEAVE_OUT */
        public string $action,
        /** Only the rows one "Add" could not add */
        public null|string $ofBatchId = null,
        public bool $beforeAdd = false,
    ) {
    }
}
