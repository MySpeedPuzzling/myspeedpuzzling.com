<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The player's word about rows of their import before they are added (docs/features/data-import/adding-screens.md
 * "The words before adding"):
 *
 * - "add it anyway" to a question an "Add" asks ($addAnyway - ImportRowRefusal's values; only the four a player may
 *   wave through: a similar result exists, somebody else's result, the file says it is on MySpeedPuzzling, an
 *   unusual time), kept in `data_import_row.answers.addAnyway`;
 * - "one result or two?" ($separate) for the rows of the file that are the same result as $equalGroupOf - the row
 *   that stands for them: true counts each row on its own, false makes them one result again.
 *
 * A whole group ("these 416 times are right") names no rows: with $refusedWith the handler takes the next chunk of
 * the rows that wait with that reason (docs/features/data-import/group-answers.md) - the same checks per row.
 *
 * It only writes the answer: nothing is added here ("Add" reads the answers - ImportRowAdder). Each row on its own:
 * the handler answers how many rows took the word and how many were passed over (they changed meanwhile, or were
 * never asked that question).
 */
readonly final class ConfirmImportRows
{
    /**
     * @param list<string> $rowIds at most ImportRowAnswers::MAX_ROWS
     * @param list<string> $addAnyway
     */
    public function __construct(
        public string $importId,
        public string $playerId,
        public array $rowIds = [],
        public array $addAnyway = [],
        public null|string $equalGroupOf = null,
        public null|bool $separate = null,
        /**
         * Instead of $rowIds: every row of the import that waits with this reason now - worked out by the handler,
         * a bounded chunk at a time ($limit rows after $afterRowId, of the "Add" $ofBatchId when given)
         */
        public null|string $refusedWith = null,
        public null|string $ofBatchId = null,
        public null|string $afterRowId = null,
        public int $limit = 200,
    ) {
    }
}
