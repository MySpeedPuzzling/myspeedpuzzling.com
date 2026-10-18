<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The time zone a browser reported (docs/features/player-timezone.md). Fills an empty `player.timezone` only - a value
 * the player chose is never overwritten - and an unknown identifier is ignored.
 */
readonly final class DetectPlayerTimezone
{
    public function __construct(
        public string $playerId,
        public null|string $timezone,
    ) {
    }
}
