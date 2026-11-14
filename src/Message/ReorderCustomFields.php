<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The order of a player's own fields: the ids given come first in that order, any field left out keeps its place
 * after them (docs/features/custom-fields.md).
 */
readonly final class ReorderCustomFields
{
    /**
     * @param list<string> $customFieldIds
     */
    public function __construct(
        public string $playerId,
        public array $customFieldIds,
    ) {
    }
}
