<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\CollectionVisibility;

readonly final class EditSystemCollection
{
    public function __construct(
        public string $playerId,
        public CollectionVisibility $visibility,
    ) {
    }
}
