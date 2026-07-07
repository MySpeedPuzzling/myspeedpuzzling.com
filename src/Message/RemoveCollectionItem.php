<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class RemoveCollectionItem
{
    public function __construct(
        public string $collectionItemId,
        public string $playerId,
    ) {
    }
}
