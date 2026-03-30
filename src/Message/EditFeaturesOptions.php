<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class EditFeaturesOptions
{
    public function __construct(
        public string $playerId,
        public bool $streakOptedOut,
        public bool $rankingOptedOut,
    ) {
    }
}
