<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The owner's own values on one puzzle of their collection (docs/features/custom-fields.md "Collection items").
 *
 * Only the fields given change: a value as it was typed (CustomValues::normalise() reads it), null or an empty text
 * takes the field's value away, a field left out keeps what it has.
 */
readonly final class SetCollectionItemCustomValues
{
    /**
     * @param array<string, mixed> $values keyed by custom field id
     */
    public function __construct(
        public string $collectionItemId,
        public string $playerId,
        public array $values,
    ) {
    }
}
