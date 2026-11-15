<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * What ShareImportLayout worked out for the sheets that follow a confirmed one, stored as the next version of each -
 * for a sheet that is still at the version it was worked out for and that the player has not confirmed meanwhile -
 * and, in the same transaction, the parsing of every such sheet that waits for one is queued.
 *
 * On fresh state: the worker's entity manager may hold the sheets as they were before the player went on.
 */
readonly final class RecordSharedImportMappings implements RequiresFreshEntityManagerState
{
    /**
     * @param array<string, array{version: int, mapping: null|array<string, mixed>, options: null|array<string, mixed>}> $sheets
     *        by sheet id: the version it was worked out for, and the new mapping and options - null: nothing to
     *        change, the sheet is only read (again) where it waits for that
     */
    public function __construct(
        public string $importId,
        public array $sheets,
    ) {
    }
}
