<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The player's saved layout is deleted (docs/features/data-import/layouts.md): the next sheet of that shape is
 * proposed from scratch. Sheets it was already applied to keep their mapping.
 */
readonly final class ForgetImportLayout
{
    public function __construct(
        public string $layoutId,
        public string $playerId,
    ) {
    }
}
