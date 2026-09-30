<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Test replacement for the `social_login.http_client` service: the league
 * providers talk to a Guzzle MockHandler instead of Google/Apple/Facebook.
 * The handler is STATIC on purpose - the WebTestCase browser reboots the
 * kernel between requests, and container-level replacements would be lost
 * right before the callback request that actually needs them.
 */
final class SocialLoginHttpMock
{
    private static null|MockHandler $handler = null;

    /** @var list<RequestInterface> */
    private static array $sentRequests = [];

    public static function client(): Client
    {
        $stack = HandlerStack::create(self::mockHandler());
        $stack->push(Middleware::mapRequest(static function (RequestInterface $request): RequestInterface {
            self::$sentRequests[] = $request;

            return $request;
        }));

        return new Client(['handler' => $stack]);
    }

    /**
     * Every request the providers sent since the last reset(), oldest first.
     *
     * @phpstan-impure
     *
     * @return list<RequestInterface>
     */
    public static function sentRequests(): array
    {
        return self::$sentRequests;
    }

    public static function queue(ResponseInterface ...$responses): void
    {
        foreach ($responses as $response) {
            self::mockHandler()->append($response);
        }
    }

    public static function reset(): void
    {
        self::$handler = new MockHandler();
        self::$sentRequests = [];
    }

    private static function mockHandler(): MockHandler
    {
        return self::$handler ??= new MockHandler();
    }
}
