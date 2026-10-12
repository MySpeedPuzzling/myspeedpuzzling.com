<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Badges;

use SpeedPuzzling\Web\Services\Xp\XpLaunchReadiness;
use SpeedPuzzling\Web\Value\HeldAtLaunch;

/**
 * The launch backfill's window (HeldAtLaunch): from the deploy to the launch switch - both from the cached launch
 * readiness, no statement per page view.
 */
readonly class LaunchStampWindow
{
    public function __construct(
        private XpLaunchReadiness $xpLaunchReadiness,
    ) {
    }

    public function current(): HeldAtLaunch
    {
        return new HeldAtLaunch($this->xpLaunchReadiness->stampedFrom(), $this->xpLaunchReadiness->readyAt());
    }
}
