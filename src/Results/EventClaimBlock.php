<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * A player who can no longer claim spots of imported event results (docs/features/event-results-import/README.md
 * "Gates") - for the admin page that lets them claim again.
 */
readonly final class EventClaimBlock
{
    /**
     * @param list<EventClaimBlockRemoval> $removals newest first
     */
    public function __construct(
        public string $playerId,
        public null|string $playerName,
        public string $playerCode,
        public DateTimeImmutable $blockedAt,
        public array $removals,
    ) {
    }
}
