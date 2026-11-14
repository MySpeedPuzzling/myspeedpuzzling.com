<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Archived = the field is hidden everywhere and its values are kept; $archived false brings it back
 * (docs/features/custom-fields.md).
 */
readonly final class ArchiveCustomField
{
    public function __construct(
        public string $customFieldId,
        public string $playerId,
        public bool $archived = true,
    ) {
    }
}
