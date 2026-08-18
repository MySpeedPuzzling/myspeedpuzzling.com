<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Record a pairing WJPF initiated, either by asking us who owns an e-mail address
 * (self::SOURCE_INBOUND) or by redeeming a code from the manual flow
 * (self::SOURCE_PAIRING_CODE).
 *
 * No outbound call is involved in either case: they already know their own IdJugador, and by
 * calling us they have told us which player it belongs to.
 */
readonly final class PairPlayerWithWjpf
{
    /** They asked us to resolve an e-mail address. */
    public const string SOURCE_INBOUND = 'wjpf_inbound';

    /** They redeemed a code the player authorised in the browser - no address involved. */
    public const string SOURCE_PAIRING_CODE = 'wjpf_pairing_code';

    public function __construct(
        public string $playerId,
        /** Their `IdJugador`. */
        public string $wjpfId,
        /** Their `NombreURL`, when they send it. */
        public null|string $wjpfNameUrl,
        /**
         * The address they matched on. Null for the code flow, where identity came from the
         * player's own session and no address had to agree.
         */
        public null|string $email,
        public string $source,
    ) {
    }
}
