<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

readonly final class PuzzlersGroup
{
    public function __construct(
        public null|string $teamId,
        /** @var non-empty-array<Puzzler> */
        public array $puzzlers,
    ) {
    }
}
