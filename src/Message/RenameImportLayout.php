<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The player gives one of their saved layouts another name (docs/features/data-import/layouts.md).
 */
readonly final class RenameImportLayout
{
    public function __construct(
        public string $layoutId,
        public string $playerId,
        public string $name,
    ) {
    }
}
