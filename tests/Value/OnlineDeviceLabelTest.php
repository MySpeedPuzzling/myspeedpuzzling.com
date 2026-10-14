<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\OnlineDeviceLabel;

/**
 * The phone as the online accuracy report groups it (OnlineDeviceLabel): the device kind and the browser with its major
 * version, from the User-Agent `device.ready` carried - Edge and Samsung Internet before Chrome (they say Chrome too),
 * Chrome on iOS as Chrome.
 */
final class OnlineDeviceLabelTest extends TestCase
{
    #[DataProvider('userAgents')]
    public function testTheDeviceAndTheBrowser(null|string $userAgent, string $device, string $browser): void
    {
        $label = OnlineDeviceLabel::fromUserAgent($userAgent);

        self::assertSame($device, $label->device);
        self::assertSame($browser, $label->browser);
    }

    /**
     * @return iterable<string, array{null|string, string, string}>
     */
    public static function userAgents(): iterable
    {
        yield 'iPhone Safari' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1', 'iPhone', 'Safari 18'];
        yield 'iPhone Chrome' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/141.0.7390.41 Mobile/15E148 Safari/604.1', 'iPhone', 'Chrome 141'];
        yield 'iPad' => ['Mozilla/5.0 (iPad; CPU OS 17_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.7 Mobile/15E148 Safari/604.1', 'iPad', 'Safari 17'];
        yield 'Android Chrome' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36', 'Android', 'Chrome 141'];
        yield 'Samsung Internet' => ['Mozilla/5.0 (Linux; Android 14; SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/27.0 Chrome/125.0.0.0 Mobile Safari/537.36', 'Android', 'Samsung Internet 27'];
        yield 'Android Firefox' => ['Mozilla/5.0 (Android 14; Mobile; rv:133.0) Gecko/133.0 Firefox/133.0', 'Android', 'Firefox 133'];
        yield 'Windows Edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', 'Windows', 'Edge 141'];
        yield 'Mac Safari' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15', 'Mac', 'Safari 26'];
        yield 'Linux Chrome (the Selenium container)' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36', 'Linux', 'Chrome 152'];
        yield 'nothing' => [null, 'other', 'other'];
        yield 'made up' => ['curl/8.0', 'other', 'other'];
    }
}
