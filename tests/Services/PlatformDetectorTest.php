<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PlatformDetector;
use SpeedPuzzling\Web\Tests\Navigation\NativeUserAgents;
use SpeedPuzzling\Web\Value\Platform;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Inside the Hotwire Native app or a browser, from the user agent (docs/features/navigation NAV-14 (b)): the new
 * Hotwire Native markers and the older ones, on both platforms; every browser is the web.
 */
final class PlatformDetectorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Platform}>
     */
    public static function userAgents(): iterable
    {
        foreach (PlatformDetector::IOS_MARKERS as $marker) {
            yield 'marker ' . $marker => ['SomeWebView/1.0 ' . $marker, Platform::Ios];
        }

        foreach (PlatformDetector::ANDROID_MARKERS as $marker) {
            yield 'marker ' . $marker => ['SomeWebView/1.0 ' . $marker, Platform::Android];
        }

        // Written out, not read from the constants: the new markers and the older ones are all recognised
        yield 'Hotwire Native iOS alone' => ['Hotwire Native iOS', Platform::Ios];
        yield 'Hotwire Native Android alone' => ['Hotwire Native Android', Platform::Android];

        foreach (NativeUserAgents::allNative() as $name => [$userAgent, $platform]) {
            yield 'app ' . $name => [$userAgent, Platform::from($platform)];
        }

        foreach (NativeUserAgents::web() as $name => [$userAgent]) {
            yield 'browser ' . $name => [$userAgent, Platform::Web];
        }

        yield 'Instagram in-app browser' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Instagram 350.0.0.0.0', Platform::Web];
        // A marker is matched as written: a browser merely saying "Native" is the web
        yield 'lower-case marker' => ['hotwire native ios', Platform::Web];
        yield 'empty' => ['', Platform::Web];
    }

    #[DataProvider('userAgents')]
    public function testTheRequestUserAgentDecidesThePlatform(string $userAgent, Platform $expected): void
    {
        $detector = new PlatformDetector(self::requestStack($userAgent));

        self::assertSame($expected, $detector->detect());
        self::assertSame($expected, PlatformDetector::forUserAgent($userAgent));
        self::assertSame($expected !== Platform::Web, $detector->isNativeApp());
        self::assertSame($expected === Platform::Web, $detector->isWeb());
        self::assertSame($expected === Platform::Ios, $detector->isIos());
        self::assertSame($expected === Platform::Android, $detector->isAndroid());
    }

    public function testWithoutARequestOrUserAgentItIsTheWeb(): void
    {
        self::assertSame(Platform::Web, new PlatformDetector(new RequestStack())->detect());
        self::assertSame(Platform::Web, PlatformDetector::forUserAgent(null));

        $requestStack = new RequestStack();
        $request = Request::create('/en/faq');
        $request->headers->remove('User-Agent');
        $requestStack->push($request);

        self::assertSame(Platform::Web, new PlatformDetector($requestStack)->detect());
    }

    private static function requestStack(string $userAgent): RequestStack
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/en/faq', server: ['HTTP_USER_AGENT' => $userAgent]));

        return $requestStack;
    }
}
