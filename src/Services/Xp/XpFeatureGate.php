<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Xp;

use SpeedPuzzling\Web\Results\PlayerProfile;

/**
 * Who sees XP, levels and achievements (`xp-launch` switch, docs/features/feature_flags.md): everybody once the launch
 * backfill was verified (XpLaunchReadiness), admins always - so they can check production before the switch.
 *
 * There is no environment flag any more: the deploy ships everything, `myspeedpuzzling:xp-backfill` calculates the
 * history, verifies it and flips the switch. Remove this gate with its call sites once the launch is settled.
 */
readonly final class XpFeatureGate
{
    public function __construct(
        private XpLaunchReadiness $xpLaunchReadiness,
    ) {
    }

    public function isVisibleFor(null|PlayerProfile $viewer): bool
    {
        return $viewer?->isAdmin === true || $this->xpLaunchReadiness->isReady();
    }

    /**
     * E-mails about XP (the activity digest) go out only after the launch - for everybody, admins included.
     */
    public function isEmailSendingEnabled(): bool
    {
        return $this->xpLaunchReadiness->isReady();
    }
}
