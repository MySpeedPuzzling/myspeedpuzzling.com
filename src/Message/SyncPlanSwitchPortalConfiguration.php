<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Create or update the Stripe portal configuration that allows the monthly <-> yearly switch
 * (docs/features/stripe.md "Switching plans"). Touches no subscription, customer or the default configuration.
 */
readonly final class SyncPlanSwitchPortalConfiguration
{
    public function __construct(
        public bool $dryRun = false,
    ) {
    }
}
