<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Xp;

use SpeedPuzzling\Web\Results\PlayerProfile;

/**
 * Feature flag `xp-system` (docs/features/feature_flags.md) for the XP / Levels / Achievements
 * launch.
 *
 * While $adminOnly is true, every user-facing surface of the gamification bundle — levels,
 * XP receipts, achievements/badges, leaderboards, digests and outbound emails — renders/sends
 * for admins only. Persistence (badge rows, XP ledger) keeps running for everyone so production
 * accumulates data silently before the launch reveal.
 *
 * Launch day: remove this gate and all its call sites — the full surface checklist lives in
 * docs/features/xp-levels/leak-inventory.md.
 */
readonly final class XpFeatureGate
{
    public function __construct(
        private bool $adminOnly = true,
    ) {
    }

    public function isVisibleFor(null|PlayerProfile $viewer): bool
    {
        if ($this->adminOnly === false) {
            return true;
        }

        return $viewer?->isAdmin === true;
    }

    /**
     * Outbound email traffic belonging to this feature (achievement congratulations, weekly
     * content digest, reveal emails) is suppressed entirely while the flag is active —
     * for everyone, admins included.
     */
    public function isEmailSendingEnabled(): bool
    {
        return $this->adminOnly === false;
    }
}
