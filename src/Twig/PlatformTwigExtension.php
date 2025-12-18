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
        ];
    }
}
