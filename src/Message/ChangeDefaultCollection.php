<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The collection every collection picker preselects (docs/features/collections/default-collection.md)
 */
readonly final class ChangeDefaultCollection
{
    public function __construct(
        public string $playerId,
        /** null = the system collection ("My puzzles") */
        public null|string $collectionId,
    ) {
    }
}
