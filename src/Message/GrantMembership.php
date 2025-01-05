<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;

readonly final class GrantMembership
{
    public function __construct(
        public string $playerId,
        public DateTimeImmutable $endsAt,
    ) {
    }
}
