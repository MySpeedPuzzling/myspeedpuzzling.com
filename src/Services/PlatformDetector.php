<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Value\Platform;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Inside the Hotwire Native app or a browser (docs/features/navigation/README.md §4.14), from the request's user agent.
 * Readonly on purpose, no memo: in FrankenPHP worker mode a memo would need ResetInterface.
 *
 * The markers are the only place they are spelled out in PHP; the same lists live in assets/head/platform_os.js
 * (the device OS head script, GEST-09) - PlatformOsScriptTest fails when the two differ.
 */
final readonly class PlatformDetector
{
    /**
     * Hotwire Native iOS sends "Hotwire Native iOS; Turbo Native iOS" (the older Turbo Native apps only the second);
     * "MySpeedPuzzling iOS" is the app's own name
     */
    public const array IOS_MARKERS = ['Hotwire Native iOS', 'Turbo Native iOS', 'MySpeedPuzzling iOS'];

    public const array ANDROID_MARKERS = ['Hotwire Native Android', 'Turbo Native Android', 'MySpeedPuzzling Android'];

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function detect(): Platform
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null) {
            return Platform::Web;
        }

        return self::forUserAgent($request->headers->get('User-Agent'));
    }

    public static function forUserAgent(null|string $userAgent): Platform
    {
        $userAgent ??= '';

        foreach (self::IOS_MARKERS as $marker) {
            if (str_contains($userAgent, $marker)) {
                return Platform::Ios;
            }
        }

        foreach (self::ANDROID_MARKERS as $marker) {
            if (str_contains($userAgent, $marker)) {
                return Platform::Android;
            }
        }

        return Platform::Web;
    }

    public function isNativeApp(): bool
    {
        return $this->detect()->isNativeApp();
    }

    public function isWeb(): bool
    {
        return $this->detect() === Platform::Web;
    }

    public function isIos(): bool
    {
        return $this->detect() === Platform::Ios;
    }

    public function isAndroid(): bool
    {
        return $this->detect() === Platform::Android;
    }
}
