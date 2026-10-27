<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Test replacement for FinishedPhotoRendition's HTTP client (the internal image proxy): every rendition asked for is a
 * few JPEG bytes, a path containing MISSING is a 404, one containing BROKEN a 502. Records the requested URLs. Static,
 * like the other HTTP mocks - the test browser reboots the kernel.
 */
final class FinishedPhotoHttpMock
{
    public const string JPEG = "\xFF\xD8\xFF\xE0fake-finished-photo\xFF\xD9";

    public const string MISSING = 'missing-on-proxy';

    public const string BROKEN = 'broken-on-proxy';

    /** @var list<string> */
    private static array $urls = [];

    public static function client(): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url): ResponseInterface {
            self::$urls[] = $url;

            if (str_contains($url, self::MISSING)) {
                return new MockResponse('', ['http_code' => 404]);
            }

            if (str_contains($url, self::BROKEN)) {
                return new MockResponse('bad gateway', ['http_code' => 502]);
            }

            return new MockResponse(self::JPEG, ['http_code' => 200, 'response_headers' => ['Content-Type' => 'image/jpeg']]);
        });
    }

    public static function reset(): void
    {
        self::$urls = [];
    }

    /**
     * @phpstan-impure
     *
     * @return list<string>
     */
    public static function urls(): array
    {
        return self::$urls;
    }
}
