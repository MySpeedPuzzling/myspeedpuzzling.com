<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A participant's phone as the accuracy report groups it (docs/features/online-competitions/README.md §5.9 "by device /
 * browser / camera placement"): the kind of device and the browser with its major version, read from the User-Agent
 * the page sent with `device.ready` - coarse on purpose (iOS reports every iPhone the same way, Chrome on Android hides
 * the model), enough to tell "Safari 18 on an iPhone" from "Chrome 141 on Android". Every browser on iOS is WebKit -
 * Chrome there (CriOS) is named as such, its engine is Safari's.
 */
final readonly class OnlineDeviceLabel
{
    public const string OTHER = 'other';

    private function __construct(
        // iPhone, iPad, Android, Mac, Windows, Linux, other
        public string $device,
        // e.g. "Safari 18", "Chrome 141", "Firefox 133", "Samsung Internet 27", "Edge 141", "other"
        public string $browser,
    ) {
    }

    public static function fromUserAgent(null|string $userAgent): self
    {
        $agent = (string) $userAgent;

        $device = match (true) {
            preg_match('/\biPhone\b|\biPod\b/', $agent) === 1 => 'iPhone',
            preg_match('/\biPad\b/', $agent) === 1 => 'iPad',
            preg_match('/\bAndroid\b/', $agent) === 1 => 'Android',
            preg_match('/\bMacintosh\b|\bMac OS X\b/', $agent) === 1 => 'Mac',
            preg_match('/\bWindows\b/', $agent) === 1 => 'Windows',
            preg_match('/\bLinux\b|\bCrOS\b/', $agent) === 1 => 'Linux',
            default => self::OTHER,
        };

        // The order matters: Edge and Samsung Internet say "Chrome" too, Chrome says "Safari"
        $browsers = [
            'Edge' => '/\bEdg(?:e|A|iOS)?\/(\d+)/',
            'Samsung Internet' => '/\bSamsungBrowser\/(\d+)/',
            'Firefox' => '/\b(?:Firefox|FxiOS)\/(\d+)/',
            'Chrome' => '/\b(?:Chrome|CriOS)\/(\d+)/',
            'Safari' => '/\bVersion\/(\d+)(?:\.\d+)*.*\bSafari\//',
        ];
        $browser = self::OTHER;

        foreach ($browsers as $name => $pattern) {
            if (preg_match($pattern, $agent, $match) === 1) {
                $browser = $name . ' ' . $match[1];

                break;
            }
        }

        return new self($device, $browser);
    }
}
