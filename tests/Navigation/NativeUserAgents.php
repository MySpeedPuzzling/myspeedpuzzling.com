<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

/**
 * User agents of the native-ready navigation tests (docs/features/navigation NAV-14, GEST-09): the Hotwire Native app
 * on both platforms (the iOS one of requirements NAV-14 Verify + its Android twin), the older app markers that keep
 * being accepted, and the web browsers a guest page must look the same for. Every bar, layer and gesture item adds
 * its native row with these (implementation plan §3.5).
 */
final class NativeUserAgents
{
    // Today's Hotwire Native user agents still carry the older "Turbo Native" marker after the new one
    public const string IOS = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Hotwire Native iOS; Turbo Native iOS; bridge-components: []';
    public const string ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 7; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.0.0 Mobile Safari/537.36 Hotwire Native Android; Turbo Native Android; bridge-components: []';

    // Older app builds: the Turbo Native marker alone, and the app's own name
    public const string LEGACY_TURBO_IOS = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Turbo Native iOS';
    public const string LEGACY_TURBO_ANDROID = 'Mozilla/5.0 (Linux; Android 13; Pixel 6; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/120.0.0.0 Mobile Safari/537.36 Turbo Native Android';
    public const string LEGACY_APP_IOS = 'MySpeedPuzzling iOS';
    public const string LEGACY_APP_ANDROID = 'MySpeedPuzzling Android';

    // Browsers
    public const string IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
    public const string PIXEL_CHROME = 'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';
    public const string DESKTOP_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

    /**
     * The Hotwire Native app: user agent => the expected data-native-app value
     *
     * @return array<string, array{string, string}>
     */
    public static function native(): array
    {
        return [
            'Hotwire Native iOS' => [self::IOS, 'ios'],
            'Hotwire Native Android' => [self::ANDROID, 'android'],
        ];
    }

    /**
     * Every app user agent, the older markers included: user agent => the expected data-native-app value
     *
     * @return array<string, array{string, string}>
     */
    public static function allNative(): array
    {
        return [
            ...self::native(),
            'Turbo Native iOS' => [self::LEGACY_TURBO_IOS, 'ios'],
            'Turbo Native Android' => [self::LEGACY_TURBO_ANDROID, 'android'],
            'MySpeedPuzzling iOS' => [self::LEGACY_APP_IOS, 'ios'],
            'MySpeedPuzzling Android' => [self::LEGACY_APP_ANDROID, 'android'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function web(): array
    {
        return [
            'iPhone Safari' => [self::IPHONE_SAFARI],
            'Pixel Chrome' => [self::PIXEL_CHROME],
            'desktop Chrome' => [self::DESKTOP_CHROME],
        ];
    }
}
