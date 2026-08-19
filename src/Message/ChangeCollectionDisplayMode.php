<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\CollectionDisplayMode;

readonly final class ChangeCollectionDisplayMode
{
    public function __construct(
        public string $playerId,
        public CollectionDisplayMode $mode,
    ) {
    }
}
