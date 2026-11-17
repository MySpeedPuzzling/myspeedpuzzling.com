<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class RenameCompetitionDisplay
{
    public function __construct(
        public string $displayId,
        public string $name,
    ) {
    }
}
