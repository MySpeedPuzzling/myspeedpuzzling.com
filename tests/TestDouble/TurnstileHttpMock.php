<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Test replacement for `turnstile.http_client` (Cloudflare's siteverify), answering by the token the test posts:
 * PASSING_TOKEN = a person on our host, WRONG_ACTION_TOKEN = a token made for another form, anything else = failed,
 * DOWN_TOKEN = no answer. Records the posted bodies. Static, like SnsHttpMock - the test browser reboots the kernel.
 */
final class TurnstileHttpMock
{
    public const string PASSING_TOKEN = 'turnstile-passed';
    public const string WRONG_ACTION_TOKEN = 'turnstile-wrong-action';
    public const string DOWN_TOKEN = 'turnstile-down';

    /** @var list<string> */
    private static array $requestBodies = [];

    public static function client(): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options): ResponseInterface {
            $body = is_string($options['body'] ?? null) ? $options['body'] : '';
            self::$requestBodies[] = $body;
            parse_str($body, $fields);

            return match ($fields['response'] ?? null) {
                self::PASSING_TOKEN => self::answer(['success' => true, 'action' => 'newsletter', 'hostname' => 'localhost']),
                self::WRONG_ACTION_TOKEN => self::answer(['success' => true, 'action' => 'login', 'hostname' => 'localhost']),
                self::DOWN_TOKEN => new MockResponse('', ['error' => 'Connection refused']),
                default => self::answer(['success' => false, 'error-codes' => ['invalid-input-response']]),
            };
        });
    }

    public static function reset(): void
    {
        self::$requestBodies = [];
    }

    /**
     * @phpstan-impure
     *
     * @return list<string>
     */
    public static function requestBodies(): array
    {
        return self::$requestBodies;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function answer(array $data): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['response_headers' => ['content-type' => 'application/json']]);
    }
}
