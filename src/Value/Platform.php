<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum Platform: string
{
    case Web = 'web';
    case Ios = 'ios';
    case Android = 'android';

    public function isNativeApp(): bool
    {
        return $this !== self::Web;
    }

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Web',
            self::Ios => 'iOS App',
            self::Android => 'Android App',
        };
    }
}
