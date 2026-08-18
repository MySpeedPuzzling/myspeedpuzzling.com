<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\UserAgentLabeler;

final class UserAgentLabelerTest extends TestCase
{
    /**
     * @return array<string, array{null|string, null|string}>
     */
    public static function provideUserAgents(): array
    {
        return [
            'Chrome on Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                'Chrome on Windows',
            ],
            'Safari on macOS' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
                'Safari on macOS',
            ],
            'Safari on iOS' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
                'Safari on iOS',
            ],
            'Chrome on iOS' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/126.0.6478.54 Mobile/15E148 Safari/604.1',
                'Chrome on iOS',
            ],
            'Firefox on Linux' => [
                'Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0',
                'Firefox on Linux',
            ],
            'Edge on Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Edg/126.0.0.0',
                'Edge on Windows',
            ],
            'Chrome on Android' => [
                'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.6478.71 Mobile Safari/537.36',
                'Chrome on Android',
            ],
            'unknown UA falls back to raw' => [
                'curl/8.6.0',
                'curl/8.6.0',
            ],
            'null stays null' => [null, null],
            'blank stays null' => ['   ', null],
        ];
    }

    #[DataProvider('provideUserAgents')]
    public function testLabel(null|string $userAgent, null|string $expected): void
    {
        self::assertSame($expected, new UserAgentLabeler()->label($userAgent));
    }

    public function testRawFallbackIsTruncated(): void
    {
        $label = new UserAgentLabeler()->label(str_repeat('a', 200));

        self::assertNotNull($label);
        self::assertSame(80, mb_strlen($label));
    }
}
