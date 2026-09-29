<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\InAppBrowser;

/**
 * A false positive takes the Google button away from a real browser, so the
 * "not an in-app browser" list matters as much as the positive one.
 */
final class InAppBrowserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool, bool}>
     */
    public static function inAppBrowsers(): iterable
    {
        yield 'Instagram iOS' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/22F76 Instagram 385.0.0.28.93 (iPhone15,3; iOS 18_5; en_US; en; scale=3.00; 1290x2796; 745621391; IABMV/1)',
            'Instagram', false, true,
        ];
        yield 'Instagram Android' => [
            'Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/AP2A.240805.005; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/128.0.6613.127 Mobile Safari/537.36 Instagram 348.0.0.40.109 Android (34/14; 420dpi; 1080x2205; Google/google; Pixel 8; shiba; shiba; en_US; 636464212)',
            'Instagram', true, false,
        ];
        yield 'Facebook iOS' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/481.0.0.40.109;FBBV/650123123;FBDV/iPhone14,5;FBMD/iPhone;FBSN/iOS;FBSV/17.6;FBSS/3;FBCR/;FBID/phone;FBLC/cs_CZ;FBOP/80]',
            'Facebook', false, true,
        ];
        yield 'Facebook Android' => [
            'Mozilla/5.0 (Linux; Android 13; SM-S911B Build/TP1A.220624.014; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/127.0.6533.103 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/478.0.0.41.86;]',
            'Facebook', true, false,
        ];
        yield 'Messenger iOS' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/MessengerForiOS;FBAV/470.0.0.35.108;FBBV/620000000;FBDV/iPhone15,2;FBMD/iPhone;FBSN/iOS;FBSV/17.5]',
            'Messenger', false, true,
        ];
        yield 'Messenger Android' => [
            'Mozilla/5.0 (Linux; Android 12; moto g(60)) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/126.0.6478.71 Mobile Safari/537.36 [FB_IAB/Orca-Android;FBAV/465.0.0.23.109;]',
            'Messenger', true, false,
        ];
        yield 'Threads iOS' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/22A3354 Barcelona 350.0.0.20.95 (iPhone16,2; iOS 18_0; en_US)',
            'Threads', false, true,
        ];
        yield 'TikTok Android' => [
            'Mozilla/5.0 (Linux; Android 14; SM-A546B Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/125.0.6422.165 Mobile Safari/537.36 trill_350104 JsSdk/1.0 NetType/WIFI Channel/googleplay AppName/musical_ly app_version/35.1.4 ByteLocale/en BytedanceWebview/d8a21c6',
            'TikTok', true, false,
        ];
        yield 'LINE iOS' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Safari Line/14.5.0',
            'LINE', false, true,
        ];
    }

    #[DataProvider('inAppBrowsers')]
    public function testRecognisesTheApp(string $userAgent, string $app, bool $android, bool $ios): void
    {
        $inAppBrowser = InAppBrowser::fromUserAgent($userAgent);

        self::assertNotNull($inAppBrowser);
        self::assertSame($app, $inAppBrowser->app);
        self::assertSame($android, $inAppBrowser->android);
        self::assertSame($ios, $inAppBrowser->ios);
    }

    /**
     * @return iterable<string, array{null|string}>
     */
    public static function realBrowsers(): iterable
    {
        yield 'no user agent' => [null];
        yield 'empty' => [''];
        yield 'Safari iOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1'];
        yield 'Chrome Android' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36'];
        yield 'Chrome iOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/129.0.6668.46 Mobile/15E148 Safari/604.1'];
        yield 'Samsung Internet' => ['Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36'];
        yield 'Firefox desktop' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14.6; rv:130.0) Gecko/20100101 Firefox/130.0'];
        yield 'Edge desktop' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.0.0'];
        // A plain Android web view (a legitimate app, a Custom Tabs fallback) is NOT one of them
        yield 'Android web view' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/AP2A.240805.005; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/128.0.6613.127 Mobile Safari/537.36'];
        yield 'Googlebot' => ['Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.6668.70 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'];
    }

    #[DataProvider('realBrowsers')]
    public function testLeavesRealBrowsersAlone(null|string $userAgent): void
    {
        self::assertNull(InAppBrowser::fromUserAgent($userAgent));
    }

    public function testChromeIntentKeepsThePathAndQuery(): void
    {
        $inAppBrowser = InAppBrowser::fromUserAgent('Mozilla/5.0 (Linux; Android 13; wv) [FB_IAB/FB4A;FBAV/478.0.0.41.86;]');
        self::assertNotNull($inAppBrowser);

        self::assertSame(
            'intent://myspeedpuzzling.com/login?return=/en/puzzle#Intent;scheme=https;package=com.android.chrome;end',
            $inAppBrowser->chromeIntentUrl('https', 'myspeedpuzzling.com', '/login?return=/en/puzzle'),
        );
    }
}
