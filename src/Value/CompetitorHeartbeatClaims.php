<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a valid competitor heartbeat token says (CompetitorHeartbeatToken): whose entry in which round - lower-case ids,
 * `entry` a RoundEntryRef string.
 */
readonly final class CompetitorHeartbeatClaims
{
    public function __construct(
        public string $roundId,
        public string $entry,
        public string $playerId,
        public bool $expired,
    ) {
    }
}
