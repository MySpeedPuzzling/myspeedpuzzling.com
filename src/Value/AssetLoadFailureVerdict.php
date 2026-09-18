<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What an asset-failure beacon from base.html.twig means, and whether anyone
 * can act on it. Only the actionable verdicts are logged at warning (and so
 * become Sentry issues); the rest stay in the logs at info, where their volume
 * is still measurable.
 */
enum AssetLoadFailureVerdict: string
{
    /** Crawler, headless or automated browser - they block stylesheets on purpose. */
    case Automation = 'automation';

    /** The page references a build older than the carried-asset retention: the HTML itself is stale. */
    case StalePage = 'stale_page';

    /** First failure of the session - the self-heal is already purging, refetching and reloading. */
    case HealPending = 'heal_pending';

    /** The forced refetch proved the server delivers intact bytes, no service worker sits in between, and the browser still refuses them. */
    case BlockedInBrowser = 'blocked_in_browser';

    /** A page younger than the carried-asset retention references an asset this release does not serve. */
    case MissingFromFreshPage = 'missing_from_fresh_page';

    /** The bytes the forced refetch received do not match the page's SRI hash. */
    case CorruptDelivery = 'corrupt_delivery';

    /** Still broken after the self-heal ran out of attempts, or when it could not run at all. */
    case HealFailed = 'heal_failed';

    public function isActionable(): bool
    {
        return match ($this) {
            self::MissingFromFreshPage, self::CorruptDelivery, self::HealFailed => true,
            self::Automation, self::StalePage, self::HealPending, self::BlockedInBrowser => false,
        };
    }

    /**
     * Each actionable verdict gets its own message, so each becomes its own
     * Sentry issue. All start with the same prefix, so one log query still
     * finds every report.
     */
    public function logMessage(): string
    {
        return match ($this) {
            self::MissingFromFreshPage => 'Client failed to load a build asset: a page younger than the carry-over retention references an asset this release does not serve',
            self::CorruptDelivery => 'Client failed to load a build asset: the downloaded bytes do not match the SRI hash',
            self::HealFailed => 'Client failed to load a build asset: still broken after the self-heal',
            self::Automation, self::StalePage, self::HealPending, self::BlockedInBrowser => 'Client failed to load a build asset (not actionable)',
        };
    }
}
