<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A social app's built-in browser (Instagram, Facebook, Messenger, Threads,
 * TikTok, LINE), recognised from the User-Agent (auth UX redesign §4.8).
 *
 * Google refuses to sign anybody in inside these embedded web views
 * ("403 disallowed_useragent"), and a sign-in link from the mail opens in the
 * phone's real browser - not in this tab. The auth pages say so up front
 * instead of letting the visitor walk into either dead end.
 *
 * Only explicit app tokens are matched. The generic Android "; wv)" marker is
 * deliberately NOT one of them: legitimate apps use web views too, and a false
 * positive would take the Google button away from a real browser.
 */
final readonly class InAppBrowser
{
    /**
     * Checked in order - Messenger and Threads builds also carry Facebook's
     * FBAN/FBAV tokens, so the more specific app comes first.
     *
     * @var array<string, string> UA pattern => app name
     */
    private const array APPS = [
        '/\bInstagram\b/' => 'Instagram',
        '/\bBarcelona\b|\bThreads\b/' => 'Threads',
        '/\bMessenger(?:Lite)?ForiOS\b|FB_IAB\/(?:MESSENGER|Orca)/' => 'Messenger',
        '/\bFBAN\/|\bFBAV\/|\bFB_IAB\//' => 'Facebook',
        '/\bmusical_ly\b|\bBytedanceWebview\b|\btrill_/' => 'TikTok',
        '/\bLine\/\d/' => 'LINE',
    ];

    private function __construct(
        public string $app,
        public bool $android,
        public bool $ios,
    ) {
    }

    public static function fromUserAgent(null|string $userAgent): null|self
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        foreach (self::APPS as $pattern => $app) {
            if (preg_match($pattern, $userAgent) === 1) {
                return new self(
                    app: $app,
                    android: stripos($userAgent, 'Android') !== false,
                    ios: preg_match('/\b(?:iPhone|iPad|iPod)\b/', $userAgent) === 1,
                );
            }
        }

        return null;
    }

    /**
     * Android's way out of a web view: an intent that hands the page to Chrome.
     * Only ever behind a real tap (browsers ignore intent navigations that the
     * user did not start).
     */
    public function chromeIntentUrl(string $scheme, string $hostWithPort, string $pathAndQuery): string
    {
        return sprintf(
            'intent://%s%s#Intent;scheme=%s;package=com.android.chrome;end',
            $hostWithPort,
            $pathAndQuery,
            $scheme,
        );
    }
}
