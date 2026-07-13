<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * One digest email for one player and one period (e.g. digestType "weekly",
 * periodKey "2026-W28"). Routed to the dedicated digest_emails queue and consumed
 * by the rate-paced digest consumer in production.
 */
readonly final class SendPlayerContentDigest
{
    public function __construct(
        public string $playerId,
        public string $digestType,
        public string $periodKey,
    ) {
    }
}
