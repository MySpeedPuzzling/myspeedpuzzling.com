<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Value\AssetLoadFailureReport;
use SpeedPuzzling\Web\Value\AssetLoadFailureVerdict;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Decides whether an asset-failure beacon is something anyone can act on.
 *
 * Measured over 7 days before this existed (8,494 reports, all at warning):
 * 50% were real browsers holding HTML weeks older than the carried-asset
 * retention (the v6 service worker replayed cached documents), so their
 * assets 404 by design; 44% were crawlers and headless scrapers that block
 * stylesheets on purpose; about 5% were real browsers failing on an asset
 * the server does have - the only ones the self-heal exists for.
 */
final readonly class AssetLoadFailureClassifier
{
    /**
     * How long a superseded asset stays servable - RETENTION_DAYS in
     * .docker/merge-previous-build.php (a test keeps the two in sync). Any page
     * younger than this must still resolve every asset it references.
     */
    public const int CARRIED_ASSET_RETENTION_DAYS = 7;

    /**
     * Self-declared crawlers and headless browsers. "cubot" is a phone brand
     * whose model name shows up in some Android user agents.
     */
    private const string AUTOMATION_USER_AGENT_PATTERN = '~(?<!cu)bot\b|crawler|spider|slurp|headless|lighthouse|google-inspectiontool|facebookexternalhit|phantomjs|selenium|puppeteer|playwright~i';

    private const string BUILD_PATH_PATTERN = '~^/build/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+$~';

    public function __construct(
        #[Autowire('%kernel.project_dir%/public/build')]
        private string $buildDirectory,
        private ClockInterface $clock,
    ) {
    }

    public function classify(AssetLoadFailureReport $report): AssetLoadFailureVerdict
    {
        if ($report->webdriver || self::isAutomatedUserAgent($report->userAgent)) {
            return AssetLoadFailureVerdict::Automation;
        }

        if ($this->assetExists($report->assetUrl) === false && !$report->refetchReceivedBytes()) {
            $pageAgeSeconds = $this->pageAgeSeconds($report);

            if ($pageAgeSeconds !== null && $pageAgeSeconds < self::CARRIED_ASSET_RETENTION_DAYS * 86400) {
                return AssetLoadFailureVerdict::MissingFromFreshPage;
            }

            // Unknown age means a page rendered before the script sent it - and
            // every asset such a page was built with that is gone by now went
            // stale more than the retention ago.
            return AssetLoadFailureVerdict::StalePage;
        }

        if ($report->refetch === 'corrupt') {
            return AssetLoadFailureVerdict::CorruptDelivery;
        }

        // Pages rendered before the script described its self-heal: they heal
        // on the first failure, so only a repeat report means it did not help.
        if ($report->scriptVersion < 2) {
            return $report->retry ? AssetLoadFailureVerdict::HealFailed : AssetLoadFailureVerdict::HealPending;
        }

        if ($report->healing === true) {
            return AssetLoadFailureVerdict::HealPending;
        }

        // The network demonstrably delivers the right bytes and there is no
        // service worker of ours in the path, yet the page still cannot use
        // them: an extension or an automated browser is blocking the load.
        if ($report->refetch === 'intact' && !$report->serviceWorkerControlled) {
            return AssetLoadFailureVerdict::BlockedInBrowser;
        }

        return AssetLoadFailureVerdict::HealFailed;
    }

    public function pageAgeSeconds(AssetLoadFailureReport $report): null|int
    {
        if ($report->renderedAt === null) {
            return null;
        }

        $age = $this->clock->now()->getTimestamp() - $report->renderedAt;

        return $age >= 0 ? $age : null;
    }

    public static function isAutomatedUserAgent(null|string $userAgent): bool
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return true;
        }

        return preg_match(self::AUTOMATION_USER_AGENT_PATTERN, $userAgent) === 1;
    }

    /**
     * Null when the URL is not a plain /build path that can be looked up.
     */
    private function assetExists(string $assetUrl): null|bool
    {
        $path = parse_url($assetUrl, PHP_URL_PATH);

        if (!is_string($path) || str_contains($path, '..') || preg_match(self::BUILD_PATH_PATTERN, $path) !== 1) {
            return null;
        }

        return is_file($this->buildDirectory . substr($path, strlen('/build')));
    }
}
