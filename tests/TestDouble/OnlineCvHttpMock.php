<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The fake CV worker of the completeness check (OnlineCvClient's HTTP client, config/services_test.php - Phase 3,
 * tools/online-cv/): every request is recorded and answered with the next queued response - a "complete" verdict
 * (verdict()) when none is queued. No test talks to a real worker. STATIC on purpose, like LiveKitHttpMock: the
 * WebTestCase browser reboots the kernel between requests. Call reset() in setUp().
 */
final class OnlineCvHttpMock
{
    public const string VERSION = 'w1-c1';

    /** @var list<array{method: string, url: string, body: string}> */
    private static array $requests = [];

    /** @var list<MockResponse> */
    private static array $queued = [];

    public static function client(): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            $body = $options['body'] ?? '';
            self::$requests[] = [
                'method' => $method,
                'url' => $url,
                'body' => is_string($body) ? $body : '',
            ];

            return array_shift(self::$queued) ?? self::verdict();
        });
    }

    /**
     * The worker's 200 answer
     *
     * @param list<array{x: int, y: int, w: int, h: int}> $gaps
     * @param array<string, mixed> $extra
     */
    public static function verdict(
        string $verdict = 'complete',
        array $gaps = [],
        float $confidence = 0.82,
        string $method = 'holes',
        string $version = self::VERSION,
        array $extra = [],
    ): MockResponse {
        return self::json([
            'verdict' => $verdict,
            'confidence' => $confidence,
            'gaps' => $gaps,
            'reason' => $verdict === 'gaps' ? count($gaps) . ' hole(s) the colour of the mat' : ($verdict === 'complete' ? 'no mat inside the puzzle' : 'a hand over the puzzle'),
            'method' => $method,
            'version' => $version,
            'ms' => 143,
            'checkMs' => 121,
            'image' => ['width' => 1920, 'height' => 1080],
            'inputs' => ['emptyMat' => 'used', 'reference' => 'none'],
            'details' => ['holes' => ['verdict' => $verdict], 'grid' => null],
            ...$extra,
        ]);
    }

    /**
     * An error of the worker - `{"error": code}` with the status (503 busy, 502 a store it could not reach, 422 ...)
     *
     * @param array<string, mixed> $extra
     */
    public static function error(int $status, string $error, array $extra = []): MockResponse
    {
        return self::json(['error' => $error, ...$extra], $status);
    }

    /**
     * @param array<string, mixed> $json
     */
    public static function json(array $json, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($json, JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    public static function queue(MockResponse ...$responses): void
    {
        foreach ($responses as $response) {
            self::$queued[] = $response;
        }
    }

    /**
     * Every request sent since the last reset(), oldest first
     *
     * @phpstan-impure
     *
     * @return list<array{method: string, url: string, body: string}>
     */
    public static function requests(): array
    {
        return self::$requests;
    }

    /**
     * The JSON body of a request
     *
     * @return array<string, mixed>
     */
    public static function body(int $index): array
    {
        $body = json_decode(self::$requests[$index]['body'] ?? '', true);

        if (!is_array($body)) {
            return [];
        }

        /** @var array<string, mixed> $body a JSON object decodes to string keys */
        return $body;
    }

    public static function reset(): void
    {
        self::$requests = [];
        self::$queued = [];
    }
}
