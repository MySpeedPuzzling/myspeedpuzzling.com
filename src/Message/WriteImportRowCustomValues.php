<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * The values of the player's own fields a row carries, written on what the row just made - after the row, in a
 * transaction of its own, like the add form does it (docs/features/custom-fields.md): it can never refuse or lose the
 * row. Carries no value: the handler reads them from the row.
 */
readonly final class WriteImportRowCustomValues implements RequiresFreshEntityManagerState
{
    public function __construct(
        public string $rowId,
        public string $playerId,
    ) {
    }
}
