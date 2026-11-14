<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\CustomFieldType;
use SpeedPuzzling\Web\Value\StandardCustomField;

/**
 * A player adds one of their own fields (docs/features/custom-fields.md) - or, with $standard, a ready-made one: then
 * the name, type, choices and places are the registry's and the rest of the message means nothing.
 */
readonly final class AddCustomField
{
    /**
     * @param list<string> $choices
     */
    public function __construct(
        public string $customFieldId,
        public string $playerId,
        public string $name,
        public CustomFieldType $type,
        public array $choices,
        public bool $forResults,
        public bool $forCollectionItems,
        public null|StandardCustomField $standard = null,
    ) {
    }

    public static function readyMade(string $customFieldId, string $playerId, StandardCustomField $standard): self
    {
        return new self(
            $customFieldId,
            $playerId,
            $standard->storedName(),
            $standard->type(),
            $standard->choices(),
            $standard->forResults(),
            $standard->forCollectionItems(),
            $standard,
        );
    }
}
