<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\HintType;

readonly final class DismissHint
{
    public function __construct(
        public string $playerId,
        public HintType $type,
    ) {
    }
}
