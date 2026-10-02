<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class UndoAutoRemoval
{
    public function __construct(
        public string $removalId,
        public string $playerId,
    ) {
    }
}
