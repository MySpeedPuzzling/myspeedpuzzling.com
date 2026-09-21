<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

/**
 * The FREE_TRIAL_ENABLED flag (docs/features/feature_flags.md) behind one door. Off hides every
 * offer and refuses new trials; a trial already running is a plain membership grant and runs on.
 */
readonly final class FreeTrialSettings
{
    public function __construct(
        private bool $freeTrialEnabled,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->freeTrialEnabled;
    }
}
