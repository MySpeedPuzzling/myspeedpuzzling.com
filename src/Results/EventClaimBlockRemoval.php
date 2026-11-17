<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One "Not on our team" of a blocked player, from result_claim_log.
 */
readonly final class EventClaimBlockRemoval
{
    public function __construct(
        public string $solvingTimeId,
        public DateTimeImmutable $at,
        public null|string $byPlayerId,
        // Who removed the spot - null when that account is gone
        public null|string $byPlayerName,
        public null|string $reason,
    ) {
    }
}
