<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

/**
 * Tiny in-house UA parser for the recent-activity page - a readable
 * "Chrome on Windows" beats a raw UA string, and a full parser dependency
 * is not worth it for five browsers and five platforms.
 */
final readonly class UserAgentLabeler
{
    private const int RAW_FALLBACK_MAX_LENGTH = 80;

    public function label(null|string $userAgent): null|string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return null;
        }

        $browser = self::browser($userAgent);
        $platform = self::platform($userAgent);

        if ($browser !== null && $platform !== null) {
            return "{$browser} on {$platform}";
        }

        if ($browser !== null) {
            return $browser;
        }

        if ($platform !== null) {
            return $platform;
        }

        return mb_substr($userAgent, 0, self::RAW_FALLBACK_MAX_LENGTH);
    }

    private static function browser(string $userAgent): null|string
    {
        // Order matters: Edge ships "Chrome/" in its UA, Chrome ships "Safari/"
        return match (true) {
            str_contains($userAgent, 'Edg/') || str_contains($userAgent, 'Edge/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera/') => 'Opera',
            str_contains($userAgent, 'Firefox/') || str_contains($userAgent, 'FxiOS/') => 'Firefox',
            str_contains($userAgent, 'CriOS/') || str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };
    }

    private static function platform(string $userAgent): null|string
    {
        // Order matters: Android UAs contain "Linux", iPhone/iPad must win over "Mac OS X"
        return match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') || str_contains($userAgent, 'iPod') => 'iOS',
            str_contains($userAgent, 'Macintosh') || str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };
    }
}
