<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Test replacement for `ses_events.http_client`: answers SNS certificate URLs with the test certificate and records
 * every request (subscription confirmations). Static, like SocialLoginHttpMock - the test browser reboots the kernel.
 */
final class SnsHttpMock
{
    private static string $certificate = '';

    /** @var list<string> */
    private static array $requestedUrls = [];

    public static function client(): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url): ResponseInterface {
            self::$requestedUrls[] = $url;

            return str_ends_with((string) parse_url($url, PHP_URL_PATH), '.pem')
                ? new MockResponse(self::$certificate)
                : new MockResponse('<ConfirmSubscriptionResponse/>');
        });
    }

    public static function useCertificate(string $certificate): void
    {
        self::$certificate = $certificate;
        self::$requestedUrls = [];
    }

    /**
     * @phpstan-impure
     *
     * @return list<string>
     */
    public static function requestedUrls(): array
    {
        return self::$requestedUrls;
    }
}
