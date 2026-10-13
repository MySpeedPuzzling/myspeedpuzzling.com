<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One page of a store's objects under a prefix, in key order (OnlineMediaStorage::listObjects()) - `nextStartAfter`
 * is the key to go on after, null when the listing is complete.
 */
final readonly class OnlineMediaObjectList
{
    /**
     * @param list<OnlineMediaObject> $objects
     */
    public function __construct(
        public array $objects,
        public null|string $nextStartAfter,
    ) {
    }
}
