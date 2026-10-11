<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Digest;

/**
 * The "We miss you" holdout (docs/features/content-digest/README.md §"Holdout"): a fixed, deterministic share of the
 * eligible players is not mailed, only logged as `holdout` - once per spell, like a send - so the comeback rate of
 * mailed and not mailed players can be compared. Deterministic (a hash of the player id with a fixed salt), so a
 * player is always on the same side, and the groups do not drift between runs.
 */
readonly final class MissYouHoldout
{
    public const int PERCENT = 10;
    private const string SALT = 'msp-miss-you-holdout-2026-10';

    public function __construct(
        private int $percent = self::PERCENT,
    ) {
    }

    public function isHeldOut(string $playerId): bool
    {
        return hexdec(substr(hash('sha256', self::SALT . strtolower($playerId)), 0, 8)) % 100 < $this->percent;
    }
}
