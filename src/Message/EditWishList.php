<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\CollectionVisibility;

readonly final class EditWishList
{
    public function __construct(
        public string $playerId,
        public CollectionVisibility $visibility,
    ) {
    }
}
