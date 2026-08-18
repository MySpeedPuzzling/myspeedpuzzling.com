<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One player queued for a WJPF lookup.
 */
readonly final class WjpfSyncCandidate
{
    public function __construct(
        public string $playerId,
        /** Lowercased, trimmed - the address we will match against their `Jugadores` table. */
        public string $email,
        public null|string $playerName,
    ) {
    }
}
