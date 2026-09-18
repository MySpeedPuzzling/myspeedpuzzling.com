<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\AssetLoadFailureClassifier;
use SpeedPuzzling\Web\Value\AssetLoadFailureReport;
use SpeedPuzzling\Web\Value\AssetLoadFailureVerdict;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class AssetLoadFailureClassifierTest extends TestCase
{
    private const string NOW = '2026-09-18 12:00:00 UTC';
    private const string BROWSER = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Mobile Safari/537.36';
    private const string SERVED = 'https://myspeedpuzzling.com/build/app.62cb6fb9.js';
    private const string GONE = 'https://myspeedpuzzling.com/build/app.9f6605dc.css';

    private string $buildDirectory;
    private AssetLoadFailureClassifier $classifier;

    protected function setUp(): void
    {
        $this->buildDirectory = sys_get_temp_dir() . '/asset-classifier-' . bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->buildDirectory . '/app.62cb6fb9.js', 'console.log(1);');
        $filesystem->dumpFile($this->buildDirectory . '/fonts/cartzilla-icons.woff', 'font');

        $this->classifier = new AssetLoadFailureClassifier($this->buildDirectory, new MockClock(self::NOW));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->buildDirectory);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideAutomatedUserAgents(): array
    {
        return [
            'Yandex resource renderer' => ['Mozilla/5.0 (compatible; YandexRenderResourcesBot/1.0; +http://yandex.com/bots) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/81.0.4044.268'],
            'bingbot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm) Chrome/116.0.1938.76 Safari/537.36'],
            'Hanalei' => ['HanaleiBot runid=beta-stage-integration-test'],
            'headless Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/146.0.0.0 Safari/537.36'],
            'Lighthouse' => ['Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Mobile Safari/537.36 Chrome-Lighthouse'],
            'empty' => [''],
        ];
    }

    #[DataProvider('provideAutomatedUserAgents')]
    public function testCrawlersAndHeadlessBrowsersAreNotActionable(string $userAgent): void
    {
        $verdict = $this->classifier->classify($this->report(self::SERVED, userAgent: $userAgent, healing: false));

        self::assertSame(AssetLoadFailureVerdict::Automation, $verdict);
        self::assertFalse($verdict->isActionable());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideBrowserUserAgents(): array
    {
        return [
            'Android Chrome' => [self::BROWSER],
            'Samsung Internet' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/30.0 Chrome/143.0.0.0 Mobile Safari/537.36'],
            'iOS Safari' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.6.1 Mobile/15E148 Safari/604.1'],
            'Firefox' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:156.0) Gecko/20100101 Firefox/156.0'],
            'a Cubot phone' => ['Mozilla/5.0 (Linux; Android 12; CUBOT X50) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Mobile Safari/537.36'],
        ];
    }

    #[DataProvider('provideBrowserUserAgents')]
    public function testRealBrowsersAreNotMistakenForAutomation(string $userAgent): void
    {
        self::assertFalse(AssetLoadFailureClassifier::isAutomatedUserAgent($userAgent));
    }

    public function testAutomatedBrowserIsNotActionable(): void
    {
        $verdict = $this->classifier->classify($this->report(self::SERVED, healing: false, webdriver: true));

        self::assertSame(AssetLoadFailureVerdict::Automation, $verdict);
    }

    public function testPageOlderThanTheRetentionReferencingAGoneAssetIsStale(): void
    {
        $verdict = $this->classifier->classify($this->report(self::GONE, healing: true, refetch: 'http-404', renderedAt: $this->secondsAgo(8 * 86400)));

        self::assertSame(AssetLoadFailureVerdict::StalePage, $verdict);
        self::assertFalse($verdict->isActionable());
    }

    /**
     * Pages rendered before the script reported its age: 4,281 reports a week,
     * all of them HTML weeks old replayed by the v6 service worker.
     */
    public function testPageOfUnknownAgeReferencingAGoneAssetIsStale(): void
    {
        $verdict = $this->classifier->classify(new AssetLoadFailureReport(
            assetUrl: self::GONE,
            page: '/',
            serviceWorkerControlled: true,
            retry: true,
            userAgent: self::BROWSER,
        ));

        self::assertSame(AssetLoadFailureVerdict::StalePage, $verdict);
    }

    /**
     * What the carried-asset retention exists to prevent - on 2026-07-30 it
     * cost 8k asset 404s in two days. It must never be quiet.
     */
    public function testFreshPageReferencingAGoneAssetIsActionable(): void
    {
        $verdict = $this->classifier->classify($this->report(self::GONE, healing: true, refetch: 'http-404', renderedAt: $this->secondsAgo(3600)));

        self::assertSame(AssetLoadFailureVerdict::MissingFromFreshPage, $verdict);
        self::assertTrue($verdict->isActionable());
    }

    /**
     * During a blue-green switch the beacon can land on the outgoing container,
     * which never had the new release's files - the client did get them.
     */
    public function testAssetThisContainerLacksButTheClientReceivedIsNotMissing(): void
    {
        $verdict = $this->classifier->classify($this->report(self::GONE, healing: true, refetch: 'intact', renderedAt: $this->secondsAgo(60)));

        self::assertSame(AssetLoadFailureVerdict::HealPending, $verdict);
    }

    public function testNestedBuildPathsAreLookedUp(): void
    {
        $verdict = $this->classifier->classify($this->report('https://myspeedpuzzling.com/build/fonts/cartzilla-icons.woff', healing: false, renderedAt: $this->secondsAgo(60)));

        self::assertSame(AssetLoadFailureVerdict::HealFailed, $verdict);
    }

    public function testPathTraversalIsNeverLookedUp(): void
    {
        $verdict = $this->classifier->classify($this->report('https://myspeedpuzzling.com/build/../../../etc/passwd', healing: false, renderedAt: $this->secondsAgo(60)));

        self::assertNotSame(AssetLoadFailureVerdict::MissingFromFreshPage, $verdict);
    }

    public function testBytesFailingTheSriHashAreActionable(): void
    {
        $verdict = $this->classifier->classify($this->report(self::SERVED, healing: true, refetch: 'corrupt'));

        self::assertSame(AssetLoadFailureVerdict::CorruptDelivery, $verdict);
        self::assertTrue($verdict->isActionable());
    }

    public function testFirstFailureTheSelfHealIsRepairingIsNotActionable(): void
    {
        $verdict = $this->classifier->classify($this->report(self::SERVED, healing: true, refetch: 'intact'));

        self::assertSame(AssetLoadFailureVerdict::HealPending, $verdict);
        self::assertFalse($verdict->isActionable());
    }

    /**
     * The headless scraper that blocks stylesheets: 2,074 reports a week under
     * an old Chrome user agent, never under a service worker, and its own fetch
     * of the same file comes back intact.
     */
    public function testStillBrokenWithIntactBytesAndNoServiceWorkerIsBlockedInTheBrowser(): void
    {
        $verdict = $this->classifier->classify($this->report(self::SERVED, healing: false, refetch: 'intact', retry: true, controlled: false));

        self::assertSame(AssetLoadFailureVerdict::BlockedInBrowser, $verdict);
        self::assertFalse($verdict->isActionable());
    }

    public function testStillBrokenUnderTheServiceWorkerIsActionable(): void
    {
        $verdict = $this->classifier->classify($this->report(self::SERVED, healing: false, refetch: 'intact', retry: true, controlled: true));

        self::assertSame(AssetLoadFailureVerdict::HealFailed, $verdict);
        self::assertTrue($verdict->isActionable());
    }

    /**
     * Sentry WEB-CC: the Chrome/118 scraper blocks fetch() as well. The heals
     * reloaded its page from this origin, yet the file it serves never arrives.
     */
    public function testStillBrokenAfterAHealWithTheRefetchRefusedAndNoServiceWorkerIsBlockedInTheBrowser(): void
    {
        $verdict = $this->classifier->classify($this->report(self::SERVED, healing: false, refetch: 'unreachable', retry: true, controlled: false));

        self::assertSame(AssetLoadFailureVerdict::BlockedInBrowser, $verdict);
        self::assertFalse($verdict->isActionable());
    }

    /**
     * Sentry WEB-CC again: a file that failed only after the heal had started was
     * never refetched, so the final report carries no verdict at all.
     */
    public function testStillBrokenAfterAHealWithoutAnyVerdictAndNoServiceWorkerIsBlockedInTheBrowser(): void
    {
        $verdict = $this->classifier->classify($this->report(self::SERVED, healing: false, refetch: null, retry: true, controlled: false));

        self::assertSame(AssetLoadFailureVerdict::BlockedInBrowser, $verdict);
        self::assertFalse($verdict->isActionable());
    }

    public function testStillBrokenWhenTheRefetchCouldNotVerifyIsActionable(): void
    {
        // No heal ran yet - an unanswered refetch may be a flaky connection
        self::assertSame(
            AssetLoadFailureVerdict::HealFailed,
            $this->classifier->classify($this->report(self::SERVED, healing: false, refetch: 'unreachable', retry: false, controlled: false)),
        );
        // Under the service worker the worker itself may be what fails the fetch
        self::assertSame(
            AssetLoadFailureVerdict::HealFailed,
            $this->classifier->classify($this->report(self::SERVED, healing: false, refetch: 'unreachable', retry: true, controlled: true)),
        );
        self::assertSame(
            AssetLoadFailureVerdict::HealFailed,
            $this->classifier->classify($this->report(self::SERVED, healing: false, refetch: null, retry: false, controlled: false)),
        );
        // No verdict under the service worker, or a refetch that timed out or got an HTTP error
        self::assertSame(
            AssetLoadFailureVerdict::HealFailed,
            $this->classifier->classify($this->report(self::SERVED, healing: false, refetch: null, retry: true, controlled: true)),
        );
        self::assertSame(
            AssetLoadFailureVerdict::HealFailed,
            $this->classifier->classify($this->report(self::SERVED, healing: false, refetch: 'timeout', retry: true, controlled: false)),
        );
        self::assertSame(
            AssetLoadFailureVerdict::HealFailed,
            $this->classifier->classify($this->report(self::SERVED, healing: false, refetch: 'http-503', retry: true, controlled: false)),
        );
    }

    public function testPagesFromBeforeTheHealReportedItsStateCountOnlyTheirRepeatReport(): void
    {
        $first = new AssetLoadFailureReport(self::SERVED, '/', true, false, self::BROWSER);
        $repeat = new AssetLoadFailureReport(self::SERVED, '/', true, true, self::BROWSER);

        self::assertSame(AssetLoadFailureVerdict::HealPending, $this->classifier->classify($first));
        self::assertSame(AssetLoadFailureVerdict::HealFailed, $this->classifier->classify($repeat));
    }

    public function testPageAgeIsMeasuredOnTheServerClock(): void
    {
        self::assertSame(90, $this->classifier->pageAgeSeconds($this->report(self::SERVED, renderedAt: $this->secondsAgo(90))));
        self::assertNull($this->classifier->pageAgeSeconds($this->report(self::SERVED)));
        self::assertNull($this->classifier->pageAgeSeconds($this->report(self::SERVED, renderedAt: $this->secondsAgo(-60))));
    }

    /**
     * "Fresh" only means something while it matches how long the image really
     * keeps superseded assets.
     */
    public function testRetentionMatchesTheBuildCarryOver(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../../.docker/merge-previous-build.php');

        self::assertSame(1, preg_match('~const RETENTION_DAYS = (\d+);~', $script, $matches));
        self::assertSame((int) $matches[1], AssetLoadFailureClassifier::CARRIED_ASSET_RETENTION_DAYS);
    }

    public function testBeaconParsingIgnoresWhatItDoesNotUnderstand(): void
    {
        self::assertNull(AssetLoadFailureReport::fromBeacon(['url' => 'https://myspeedpuzzling.com/img/logo.png'], self::BROWSER));
        self::assertNull(AssetLoadFailureReport::fromBeacon(['url' => ['nested']], self::BROWSER));

        $report = AssetLoadFailureReport::fromBeacon([
            'v' => 2,
            'url' => self::SERVED,
            'page' => '/en/hub',
            'controlled' => 'yes',
            'retry' => true,
            'healing' => 'no',
            'refetch' => '<script>',
            'rendered' => '1789999999',
            'webdriver' => 1,
        ], self::BROWSER);

        self::assertNotNull($report);
        self::assertSame(2, $report->scriptVersion);
        self::assertFalse($report->serviceWorkerControlled);
        self::assertTrue($report->retry);
        self::assertNull($report->healing);
        self::assertNull($report->refetch);
        self::assertNull($report->renderedAt);
        self::assertFalse($report->webdriver);

        $legacy = AssetLoadFailureReport::fromBeacon(['url' => self::SERVED, 'refetch' => 'http-404'], null);

        self::assertNotNull($legacy);
        self::assertSame(1, $legacy->scriptVersion);
        self::assertSame('http-404', $legacy->refetch);
    }

    private function report(
        string $assetUrl,
        string $userAgent = self::BROWSER,
        null|bool $healing = null,
        null|string $refetch = null,
        null|int $renderedAt = null,
        bool $retry = false,
        bool $controlled = true,
        bool $webdriver = false,
    ): AssetLoadFailureReport {
        return new AssetLoadFailureReport(
            assetUrl: $assetUrl,
            page: '/en/hub',
            serviceWorkerControlled: $controlled,
            retry: $retry,
            userAgent: $userAgent,
            scriptVersion: 2,
            healing: $healing,
            refetch: $refetch,
            renderedAt: $renderedAt,
            webdriver: $webdriver,
        );
    }

    private function secondsAgo(int $seconds): int
    {
        return new DateTimeImmutable(self::NOW)->getTimestamp() - $seconds;
    }
}
