<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class UpdatePlayerLocale
{
    public function __construct(
        public string $playerId,
        public string $locale,
    ) {
    }
}
