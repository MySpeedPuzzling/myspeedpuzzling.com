<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\PlatformDetector;
use SpeedPuzzling\Web\Value\Platform;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

final class PlatformTwigExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        readonly private PlatformDetector $platformDetector,
    ) {
    }

    /**
     * @return array<string, Platform>
     */
    public function getGlobals(): array
    {
        return [
            'platform' => $this->platformDetector->detect(),
        ];
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('is_native_app', $this->platformDetector->isNativeApp(...)),
            new TwigFunction('is_web', $this->platformDetector->isWeb(...)),
            new TwigFunction('is_ios', $this->platformDetector->isIos(...)),
            new TwigFunction('is_android', $this->platformDetector->isAndroid(...)),
            new TwigFunction('native_app_attribute', $this->nativeAppAttribute(...), ['is_safe' => ['html']]),
            new TwigFunction('layer_frame', $this->layerFrame(...)),
        ];
    }

    /**
     * ` data-native-app="ios|android"` for the <html> element inside the app, nothing on the web
     * (docs/features/navigation/README.md §4.14 (b)). Read in JS only through assets/platform.js.
     */
    public function nativeAppAttribute(): string
    {
        $platform = $this->platformDetector->detect();

        return $platform->isNativeApp() ? ' data-native-app="' . $platform->value . '"' : '';
    }

    /**
     * The data-turbo-frame of a link opening a layer (a sheet in modal-frame): that frame on the web, `_top` inside
     * the app, where the same link becomes a visit to the layer's plain route - a screen the app presents itself
     * (README §4.14 (a)).
     */
    public function layerFrame(string $frame): string
    {
        return $this->platformDetector->isNativeApp() ? '_top' : $frame;
    }
}
