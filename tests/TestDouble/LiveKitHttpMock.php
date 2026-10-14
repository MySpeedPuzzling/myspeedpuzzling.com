<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The test replacement of LiveKit's server API (LiveKitApi's HTTP client, config/services_test.php): every request is
 * recorded and answered with the next queued response - `{}` (200) when none is queued. No test ever talks to a real
 * LiveKit. STATIC on purpose, like SocialLoginHttpMock: the WebTestCase browser reboots the kernel between requests, a
 * container-level replacement would be gone by the request that needs it. Call reset() in setUp().
 */
final class LiveKitHttpMock
{
    /** @var list<array{method: string, url: string, headers: array<string, list<string>>, body: string}> */
    private static array $requests = [];

    /** @var list<MockResponse> */
    private static array $queued = [];

    public static function client(): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            $headers = $options['normalized_headers'] ?? [];
            $body = $options['body'] ?? '';

            /** @var array<string, list<string>> $headers */
            self::$requests[] = [
                'method' => $method,
                'url' => $url,
                'headers' => $headers,
                'body' => is_string($body) ? $body : '',
            ];

            return array_shift(self::$queued) ?? self::answer([]);
        });
    }

    /**
     * @param array<string, mixed> $json
     */
    public static function answer(array $json, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($json === [] ? new \stdClass() : $json, JSON_THROW_ON_ERROR), [
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
     * @return list<array{method: string, url: string, headers: array<string, list<string>>, body: string}>
     */
    public static function requests(): array
    {
        return self::$requests;
    }

    public static function reset(): void
    {
        self::$requests = [];
        self::$queued = [];
    }
}
