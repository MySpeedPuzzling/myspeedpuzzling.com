<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Services\PlatformDetector;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\Navigation\NativeUserAgents;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The data-os head script (docs/features/navigation GEST-09, README §4.13 "Platform attribute"): run exactly as
 * /en/faq renders it in a fake browser per device (tests/platform-os-harness.js), in the order of README §4.13 -
 * the app's markers (read from PlatformDetector, so PHP and JS cannot drift), UA-CH platform when non-empty, Android
 * in the user agent, iOS by user agent or iPadOS desktop UA with touch points, -webkit-touch-callout, else other.
 * The inline text is the file itself and the same on every page (Turbo runs a head script again only when its text
 * changes), and it is the first script of the head (plan §3.11).
 */
final class PlatformOsScriptTest extends WebTestCase
{
    private const string FILE = __DIR__ . '/../assets/head/platform_os.js';

    private const string MAC_SAFARI = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15';
    private const string LINUX_CHROME = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

    /** @var null|array<string, array{name: string, os: null|string, threw?: string}> */
    private static null|array $results = null;

    private static null|string $renderedScript = null;

    /**
     * name => [name, user agent, UA-CH platform (null = no userAgentData), maxTouchPoints, CSS: 'touch-callout' | 'plain' | 'none', expected data-os]
     *
     * @return array<string, array{string, string, null|string, int, string, string}>
     */
    public static function devices(): array
    {
        /** @var array<string, array{string, null|string, int, string, string}> $devices */
        $devices = [
            // requirements GEST-09 Verify
            'iPhone Safari' => [NativeUserAgents::IPHONE_SAFARI, null, 5, 'touch-callout', 'ios'],
            'iPhone Chrome (CriOS)' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/129.0.6668.69 Mobile/15E148 Safari/604.1', null, 5, 'touch-callout', 'ios'],
            'iPhone Firefox (FxiOS)' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/131.0 Mobile/15E148 Safari/605.1.15', null, 5, 'touch-callout', 'ios'],
            // Decided by the touch points alone (no callout in the fake)
            'iPad desktop UA, maxTouchPoints 5' => [self::MAC_SAFARI, null, 5, 'plain', 'ios'],
            'Mac Safari, maxTouchPoints 0' => [self::MAC_SAFARI, null, 0, 'plain', 'other'],
            'Pixel Chrome (UA-CH Android)' => [NativeUserAgents::PIXEL_CHROME, 'Android', 5, 'plain', 'android'],
            'Samsung Internet' => ['Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36', 'Android', 5, 'plain', 'android'],
            'Firefox Android (no UA-CH)' => ['Mozilla/5.0 (Android 14; Mobile; rv:131.0) Gecko/131.0 Firefox/131.0', null, 5, 'plain', 'android'],
            'Android WebView' => ['Mozilla/5.0 (Linux; Android 14; Pixel 7; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.0.0 Mobile Safari/537.36', null, 5, 'plain', 'android'],
            'Chrome Android desktop site (Linux UA)' => [self::LINUX_CHROME, null, 5, 'plain', 'other'],
            'Windows' => [NativeUserAgents::DESKTOP_CHROME, 'Windows', 0, 'plain', 'other'],
            'ChromeOS' => ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36', 'Chrome OS', 10, 'plain', 'other'],
            'Hotwire Native iOS' => [NativeUserAgents::IOS, null, 5, 'touch-callout', 'ios'],
            'Hotwire Native Android' => [NativeUserAgents::ANDROID, 'Android', 5, 'plain', 'android'],
            'spoofed desktop UA with -webkit-touch-callout' => [self::MAC_SAFARI, null, 0, 'touch-callout', 'ios'],
            // implementation plan GEST-09
            'Chrome Android desktop site (UA-CH Linux)' => [self::LINUX_CHROME, 'Linux', 5, 'plain', 'other'],
            'UA-CH iOS (Chromium emulating an iPhone)' => [NativeUserAgents::IPHONE_SAFARI, 'iOS', 5, 'plain', 'ios'],
            'CSS undefined' => [self::MAC_SAFARI, null, 0, 'none', 'other'],
            // UA-CH is authoritative only when non-empty
            'empty UA-CH platform falls through' => [NativeUserAgents::PIXEL_CHROME, '', 5, 'plain', 'android'],
            'UA-CH macOS wins over the touch points' => [self::MAC_SAFARI, 'macOS', 5, 'touch-callout', 'other'],
            // The app's markers come before UA-CH
            'a marker before UA-CH' => ['SomeWebView/1.0 ' . PlatformDetector::IOS_MARKERS[0], 'macOS', 0, 'plain', 'ios'],
        ];

        // PHP <-> JS parity: every marker of PlatformDetector decides alone
        foreach (PlatformDetector::IOS_MARKERS as $marker) {
            $devices['marker ' . $marker] = ['SomeWebView/1.0 ' . $marker, null, 0, 'plain', 'ios'];
        }

        foreach (PlatformDetector::ANDROID_MARKERS as $marker) {
            $devices['marker ' . $marker] = ['SomeWebView/1.0 ' . $marker, null, 0, 'plain', 'android'];
        }

        $rows = [];

        foreach ($devices as $name => [$userAgent, $uaCh, $maxTouchPoints, $css, $expected]) {
            $rows[$name] = [$name, $userAgent, $uaCh, $maxTouchPoints, $css, $expected];
        }

        return $rows;
    }

    #[DataProvider('devices')]
    public function testTheDeviceGetsItsOs(string $name, string $userAgent, null|string $uaCh, int $maxTouchPoints, string $css, string $expected): void
    {
        $result = $this->results()[$name] ?? null;

        self::assertNotNull($result, $name);
        self::assertArrayNotHasKey('threw', $result, $name);
        self::assertSame($expected, $result['os'], $name . ' (' . $userAgent . ')');
    }

    public function testThereAreEnoughDevices(): void
    {
        self::assertGreaterThanOrEqual(18, count(self::devices()));
    }

    public function testABrokenNavigatorIsOtherAndNeverThrows(): void
    {
        $result = $this->results()['broken navigator'] ?? null;

        self::assertSame(['name' => 'broken navigator', 'os' => 'other'], $result);
    }

    public function testTheMarkerListsAreThoseOfThePhpDetector(): void
    {
        $script = $this->renderedScript();

        foreach (['IOS_MARKERS' => PlatformDetector::IOS_MARKERS, 'ANDROID_MARKERS' => PlatformDetector::ANDROID_MARKERS] as $name => $markers) {
            self::assertSame(1, preg_match('~var ' . $name . ' = \[([^\]]*)\]~', $script, $matches), $name);
            preg_match_all("~'([^']*)'~", $matches[1], $listed);
            self::assertSame($markers, $listed[1], $name . ' in assets/head/platform_os.js must equal PlatformDetector::' . $name);
        }
    }

    public function testTheInlineScriptIsTheFileOnEveryPage(): void
    {
        $file = trim((string) file_get_contents(self::FILE));
        self::assertSame($file, $this->renderedScript());

        // The same text on a Czech page and on a member's Hub: never a per-page value in it
        $browser = $this->browser();
        $browser->request('GET', '/caste-dotazy');
        self::assertResponseIsSuccessful();
        self::assertSame($file, self::extract((string) $browser->getResponse()->getContent()));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();
        self::assertSame($file, self::extract((string) $browser->getResponse()->getContent()));
    }

    public function testItIsTheFirstScriptOfTheHead(): void
    {
        $browser = $this->browser();
        $browser->request('GET', '/en/faq');
        $html = (string) $browser->getResponse()->getContent();

        $positions = [
            'viewport meta' => strpos($html, '<meta name="viewport"'),
            'data-os script' => strpos($html, '<script>' . trim((string) file_get_contents(self::FILE))),
            'asset-failure script' => strpos($html, '/-/asset-load-failure'),
            'msp-rendered-at meta' => strpos($html, '<meta name="msp-rendered-at"'),
            'critical style' => strpos($html, '<style>'),
            'stylesheet link' => strpos($html, '<link rel="stylesheet"'),
        ];

        // Head order (plan §3.11): viewport → head flags → asset-failure → msp-rendered-at pair → critical CSS → stylesheet
        $previous = null;

        foreach ($positions as $what => $position) {
            self::assertIsInt($position, $what . ' not found');

            if ($previous !== null) {
                self::assertGreaterThan($previous[1], $position, $what . ' must come after the ' . $previous[0]);
            }

            $previous = [$what, $position];
        }

        self::assertSame(0, preg_match('~<script\b~', substr($html, 0, (int) $positions['data-os script'])), 'No script before the data-os script');
    }

    public function testTheFileIsSafeToInline(): void
    {
        $file = (string) file_get_contents(self::FILE);

        self::assertStringNotContainsStringIgnoringCase('</script', $file);
        self::assertStringNotContainsString('<!--', $file);
        // ES5: no arrow functions, let/const, template literals, includes()
        self::assertDoesNotMatchRegularExpression('~=>|\b(let|const)\s|`|\.includes\(~', $file);
        self::assertLessThan(1300, strlen($file), 'Inlined into every page');
    }

    private function browser(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return self::createClient();
    }

    private function renderedScript(): string
    {
        if (self::$renderedScript === null) {
            $browser = $this->browser();
            $browser->request('GET', '/en/faq');
            self::assertResponseIsSuccessful();
            self::$renderedScript = self::extract((string) $browser->getResponse()->getContent());
        }

        return self::$renderedScript;
    }

    private static function extract(string $html): string
    {
        self::assertSame(
            1,
            preg_match('~<meta name="viewport"[^>]*>\s*<script>(.*?)</script>~s', $html, $matches),
            'The data-os script must directly follow the viewport meta (templates/_head_flags.html.twig)',
        );

        return trim($matches[1]);
    }

    /**
     * @return array<string, array{name: string, os: null|string, threw?: string}>
     */
    private function results(): array
    {
        if (self::$results === null) {
            $cases = [];

            foreach (self::devices() as [$name, $userAgent, $uaCh, $maxTouchPoints, $css]) {
                $cases[] = ['name' => $name, 'ua' => $userAgent, 'uaCh' => $uaCh, 'maxTouchPoints' => $maxTouchPoints, 'css' => $css];
            }

            $cases[] = ['name' => 'broken navigator', 'ua' => '', 'uaCh' => null, 'css' => 'plain', 'brokenNavigator' => true];

            $node = new ExecutableFinder()->find('node');
            self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

            $process = new Process([$node, __DIR__ . '/platform-os-harness.js']);
            $process->setInput(json_encode(['script' => $this->renderedScript(), 'cases' => $cases], JSON_THROW_ON_ERROR));
            $process->mustRun();

            /** @var list<array{name: string, os: null|string, threw?: string}> $decoded */
            $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::$results = array_column($decoded, null, 'name');
        }

        return self::$results;
    }
}
