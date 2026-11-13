<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Sandbox;

use Symfony\Component\HttpFoundation\Request;

/**
 * The sandbox's front door (docs/features/sandbox/README.md "Search engines"): a person sees once what the sandbox is
 * and presses "Enter", which remembers itself in a cookie. Until then every page answers the welcome page with 401 +
 * noindex - crawlers never press a button, so none of them ever gets a real page.
 *
 * What machines call stays open (the same list as the edge router on lily.srv): the API, the OAuth2 token endpoint,
 * the internal API, health and telemetry, robots.txt.
 */
final class SandboxGate
{
    public const string COOKIE = 'msp_sandbox_entered';

    public const int COOKIE_DAYS = 365;

    public const array LOCALES = ['en', 'cs', 'de', 'es', 'fr', 'ja'];

    private const array OPEN_PATH_PREFIXES = [
        '/api/',
        '/internal-api/',
        '/-/',
    ];

    private const array OPEN_PATHS = [
        '/oauth2/token',
        '/robots.txt',
        '/sandbox/enter',
    ];

    public static function hasEntered(Request $request): bool
    {
        return $request->cookies->get(self::COOKIE) === '1';
    }

    public static function isOpenPath(string $path): bool
    {
        if (in_array($path, self::OPEN_PATHS, true)) {
            return true;
        }

        foreach (self::OPEN_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The language of the page asked for (its URL prefix; Czech pages have none), else the browser's
     */
    public static function locale(Request $request): string
    {
        $segment = explode('/', ltrim($request->getPathInfo(), '/'))[0];

        if (in_array($segment, self::LOCALES, true)) {
            return $segment;
        }

        return $request->getPreferredLanguage(self::LOCALES) ?? 'en';
    }
}
