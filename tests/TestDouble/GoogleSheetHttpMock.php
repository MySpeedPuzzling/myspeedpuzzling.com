<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Test replacement for `data_import.google_http_client` (Google Sheets' export): answers with what the test queued,
 * one answer per request, and records every request with the address it was pinned to. A request nobody queued an
 * answer for is a 500 - no test ever reaches Google. Static, like SnsHttpMock - the test browser reboots the kernel.
 */
final class GoogleSheetHttpMock
{
    /** @var list<MockResponse> */
    private static array $answers = [];

    /** @var list<array{url: string, resolve: array<mixed>, maxRedirects: mixed}> */
    private static array $requests = [];

    public static function client(): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options): ResponseInterface {
            self::$requests[] = [
                'url' => $url,
                'resolve' => is_array($options['resolve'] ?? null) ? $options['resolve'] : [],
                'maxRedirects' => $options['max_redirects'] ?? null,
            ];

            return array_shift(self::$answers) ?? new MockResponse('nothing queued', ['http_code' => 500]);
        });
    }

    public static function reset(): void
    {
        self::$answers = [];
        self::$requests = [];
    }

    public static function answer(MockResponse ...$answers): void
    {
        self::$answers = [...self::$answers, ...array_values($answers)];
    }

    /**
     * The export as Google hands it over: one redirect from docs.google.com to its download host, then the file.
     */
    public static function answerWithExport(string $spreadsheetId, string $content, null|string $contentDisposition = null): void
    {
        self::answer(
            self::redirect(self::downloadUrl($spreadsheetId)),
            new MockResponse($content, ['response_headers' => array_filter([
                'content-type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'content-disposition' => $contentDisposition,
            ])]),
        );
    }

    public static function redirect(string $location, int $status = 307): MockResponse
    {
        return new MockResponse('', ['http_code' => $status, 'response_headers' => ['location' => $location]]);
    }

    public static function downloadUrl(string $spreadsheetId): string
    {
        return 'https://doc-0a-1b-sheets.googleusercontent.com/export/made-up/' . $spreadsheetId . '?format=xlsx';
    }

    /**
     * @phpstan-impure
     *
     * @return list<string>
     */
    public static function requestedUrls(): array
    {
        return array_column(self::$requests, 'url');
    }

    /**
     * @phpstan-impure
     *
     * @return list<array{url: string, resolve: array<mixed>, maxRedirects: mixed}>
     */
    public static function requests(): array
    {
        return self::$requests;
    }
}
