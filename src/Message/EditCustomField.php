<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * A player renames one of their own fields, changes its choices or where it is for. Never the type, never a
 * ready-made field (docs/features/custom-fields.md).
 */
readonly final class EditCustomField
{
    /**
     * @param list<string> $choices
     */
    public function __construct(
        public string $customFieldId,
        public string $playerId,
        public string $name,
        public array $choices,
        public bool $forResults,
        public bool $forCollectionItems,
    ) {
    }
}
