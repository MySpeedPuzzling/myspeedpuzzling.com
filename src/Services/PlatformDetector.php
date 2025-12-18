<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Value\Platform;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class PlatformDetector
{
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

        $userAgent = $request->headers->get('User-Agent', '');

        // Hotwire Native iOS sends: Turbo Native iOS
        if (str_contains($userAgent, 'Turbo Native iOS') || str_contains($userAgent, 'MySpeedPuzzling iOS')) {
            return Platform::Ios;
        }

        // Hotwire Native Android sends: Turbo Native Android
        if (str_contains($userAgent, 'Turbo Native Android') || str_contains($userAgent, 'MySpeedPuzzling Android')) {
            return Platform::Android;
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
