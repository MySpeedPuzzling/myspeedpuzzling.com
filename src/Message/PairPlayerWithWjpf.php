<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Record a pairing that WJPF initiated on their side (POST /api/v0/wjpf-pairing).
 *
 * No outbound call is involved: they already know their own IdJugador, and by calling us
 * they have told us which player it belongs to.
 */
readonly final class PairPlayerWithWjpf
{
    public function __construct(
        public string $playerId,
        /** Their `IdJugador`. */
        public string $wjpfId,
        /** Their `NombreURL`, when they send it. */
        public null|string $wjpfNameUrl,
        public string $email,
    ) {
    }
}
