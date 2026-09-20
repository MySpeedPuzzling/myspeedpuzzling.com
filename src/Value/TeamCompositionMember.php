<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

readonly final class TeamCompositionMember
{
    public function __construct(
        public string $memberKey,
        public null|string $playerId,
        public null|string $guestName,
        // Order in which the person was entered - display only, never part of the identity
        public int $position,
    ) {
    }
}
