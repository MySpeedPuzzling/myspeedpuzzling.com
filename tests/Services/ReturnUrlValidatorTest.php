<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\ReturnUrlValidator;
use Symfony\Component\HttpFoundation\Request;

final class ReturnUrlValidatorTest extends TestCase
{
    #[DataProvider('provideSanitizeData')]
    public function testSanitize(string $candidate, null|string $expected): void
    {
        $validator = new ReturnUrlValidator();
        $request = Request::create('https://myspeedpuzzling.com/en/profile');

        self::assertSame($expected, $validator->sanitize($candidate, $request));
    }

    /**
     * @return Generator<string, array{string, null|string}>
     */
    public static function provideSanitizeData(): Generator
    {
        yield 'empty string returns null' => ['', null];
        yield 'whitespace-only string returns null' => ['   ', null];

        yield 'same-origin absolute path is accepted' => ['/en/puzzle/abc', '/en/puzzle/abc'];
        yield 'path with query string is accepted' => ['/en/profile?tab=solved', '/en/profile?tab=solved'];
        yield 'path with fragment is accepted' => ['/en/profile#anchor', '/en/profile#anchor'];

        yield 'protocol-relative URL is rejected' => ['//evil.example/x', null];
        yield 'backslash-prefixed path is rejected (browser normalization bypass)' => ['/\\evil.example/x', null];
        yield 'double-slash path is rejected even when second char is slash' => ['//', null];

        yield 'cross-host absolute URL is rejected' => ['https://evil.example/path', null];
        yield 'same-host https absolute URL is accepted' => [
            'https://myspeedpuzzling.com/en/puzzle/abc',
            'https://myspeedpuzzling.com/en/puzzle/abc',
        ];
        yield 'same-host http absolute URL is accepted' => [
            'http://myspeedpuzzling.com/en/puzzle/abc',
            'http://myspeedpuzzling.com/en/puzzle/abc',
        ];

        yield 'javascript scheme with same-host authority is rejected' => [
            'javascript://myspeedpuzzling.com/%0aalert(1)',
            null,
        ];
        yield 'data scheme is rejected' => ['data:text/html,<script>alert(1)</script>', null];
        yield 'file scheme is rejected' => ['file:///etc/passwd', null];
        yield 'string with no scheme and no leading slash is rejected' => ['evil.example/path', null];

        yield 'host comparison is case-insensitive' => [
            'https://MYSPEEDPUZZLING.COM/en',
            'https://MYSPEEDPUZZLING.COM/en',
        ];

        yield 'whitespace is trimmed before validation' => ['  /en/profile  ', '/en/profile'];
    }
}
