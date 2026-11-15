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
 * It only writes the answer: nothing is added here ("Add" reads the answers - ImportRowAdder). All of it or none.
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
    ) {
    }
}
