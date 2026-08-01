<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\ReturnUrl;

final class ReturnUrlTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileValues(): iterable
    {
        yield 'absolute https URL' => ['https://evil.com'];
        yield 'absolute http URL' => ['http://evil.com'];
        yield 'scheme-relative' => ['//evil.com'];
        yield 'scheme-relative with path' => ['//evil.com/puzzle'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'data scheme' => ['data:text/html,<script>alert(1)</script>'];
        // Browsers normalise backslashes to slashes - the case every hand-rolled
        // check in this codebase missed before ReturnUrl existed
        yield 'backslash scheme-relative' => ['/\\evil.com'];
        yield 'double backslash' => ['\\\\evil.com'];
        yield 'mixed slash backslash' => ['/\\/evil.com'];
        yield 'encoded scheme-relative' => ['%2F%2Fevil.com'];
        yield 'encoded backslash' => ['/%5Cevil.com'];
        yield 'double-encoded scheme-relative' => ['%252F%252Fevil.com'];
        yield 'encoded absolute URL' => ['https%3A%2F%2Fevil.com'];
        yield 'CRLF header injection' => ["/puzzle\r\nLocation: https://evil.com"];
        yield 'newline only' => ["/puzzle\nSet-Cookie: a=b"];
        yield 'encoded CRLF' => ['/puzzle%0D%0ALocation:%20https://evil.com'];
        yield 'null byte' => ["/puzzle\0"];
        yield 'encoded null byte' => ['/puzzle%00'];
        yield 'relative path' => ['puzzle/detail'];
        yield 'parent traversal without leading slash' => ['../admin'];
        yield 'empty string' => [''];
        yield 'bare scheme' => ['file:///etc/passwd'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptableValues(): iterable
    {
        yield 'root' => ['/'];
        yield 'simple path' => ['/en/puzzle'];
        yield 'path with uuid' => ['/en/puzzle/019c386e-02e6-73c8-9ed9-5b82213f87d5'];
        yield 'path with query' => ['/en/puzzle?search=cats&page=2'];
        yield 'path with fragment' => ['/en/puzzle#results'];
        yield 'percent-encoded space' => ['/en/puzzle/some%20name'];
        yield 'accented characters' => ['/cs/skladacka/kočka'];
        yield 'path that merely mentions a host' => ['/en/redirect-notice?to=evil.com'];
    }

    #[DataProvider('hostileValues')]
    public function testHostileValuesAreRejected(string $value): void
    {
        self::assertNull(ReturnUrl::tryFrom($value), sprintf('"%s" must not be accepted', addcslashes($value, "\0..\37")));
    }

    #[DataProvider('acceptableValues')]
    public function testSameSitePathsAreAccepted(string $value): void
    {
        $returnUrl = ReturnUrl::tryFrom($value);

        self::assertNotNull($returnUrl, sprintf('"%s" should be accepted', $value));
        // The ORIGINAL value is preserved - decoding is only ever used to inspect
        // the value, never to rewrite what we redirect to
        self::assertSame($value, $returnUrl->path);
        self::assertSame($value, (string) $returnUrl);
    }

    public function testNullIsRejected(): void
    {
        self::assertNull(ReturnUrl::tryFrom(null));
    }

    public function testDeeplyNestedEncodingFailsClosed(): void
    {
        // Nothing legitimate nests this deep; the value must not be unwrapped forever
        $value = '//evil.com';

        for ($i = 0; $i < 8; $i++) {
            $value = rawurlencode($value);
        }

        self::assertNull(ReturnUrl::tryFrom('/' . $value));
        self::assertNull(ReturnUrl::tryFrom($value));
    }
}
