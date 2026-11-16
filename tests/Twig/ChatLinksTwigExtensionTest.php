<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Twig\ChatLinksTwigExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ChatLinksTwigExtensionTest extends TestCase
{
    private const string EXTERNAL = ' target="_blank" rel="noopener noreferrer nofollow ugc"';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function messages(): iterable
    {
        yield 'plain text is only escaped' => ['5 < 6 & "ok" isn\'t it', '5 &lt; 6 &amp; &quot;ok&quot; isn&apos;t it'];
        yield 'an address mid sentence' => [
            'look at https://example.com/a?b=1&c=2 please',
            'look at <a href="https://example.com/a?b=1&amp;c=2"' . self::EXTERNAL . '>https://example.com/a?b=1&amp;c=2</a> please',
        ];
        yield 'the full stop ends the sentence' => [
            'See https://example.com.',
            'See <a href="https://example.com"' . self::EXTERNAL . '>https://example.com</a>.',
        ];
        yield 'brackets around the address' => [
            '(https://example.com/x)',
            '(<a href="https://example.com/x"' . self::EXTERNAL . '>https://example.com/x</a>)',
        ];
        yield 'a bracket that belongs to the address' => [
            'https://en.wikipedia.org/wiki/Puzzle_(game)',
            '<a href="https://en.wikipedia.org/wiki/Puzzle_(game)"' . self::EXTERNAL . '>https://en.wikipedia.org/wiki/Puzzle_(game)</a>',
        ];
        yield 'quotes around the address' => [
            '"https://example.com"',
            '&quot;<a href="https://example.com"' . self::EXTERNAL . '>https://example.com</a>&quot;',
        ];
        yield 'markup inside an address stays text' => [
            'https://example.com/"><script>alert(1)</script>',
            '<a href="https://example.com/"' . self::EXTERNAL . '>https://example.com/</a>&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;',
        ];
        yield 'only http and https' => ['javascript:alert(1) ftp://example.com', 'javascript:alert(1) ftp://example.com'];
        yield 'our own pages open in place' => [
            'https://myspeedpuzzling.com/en/puzzle/1',
            '<a href="https://myspeedpuzzling.com/en/puzzle/1">https://myspeedpuzzling.com/en/puzzle/1</a>',
        ];
        yield 'the sandbox is ours too' => [
            'https://sandbox.myspeedpuzzling.com/en',
            '<a href="https://sandbox.myspeedpuzzling.com/en">https://sandbox.myspeedpuzzling.com/en</a>',
        ];
        yield 'the host the page is served from' => [
            'http://localhost:8080/en/hub',
            '<a href="http://localhost:8080/en/hub">http://localhost:8080/en/hub</a>',
        ];
        yield 'a scheme alone is no link' => ['https:// nothing', 'https:// nothing'];
    }

    #[DataProvider('messages')]
    public function testLinks(string $message, string $expected): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('http://localhost:8080/en/messages/1'));

        self::assertSame($expected, (new ChatLinksTwigExtension($requestStack))->linkify($message));
    }

    public function testWithoutARequestOnlyOurDomainIsInternal(): void
    {
        $extension = new ChatLinksTwigExtension(new RequestStack());

        self::assertSame(
            '<a href="http://localhost/x"' . self::EXTERNAL . '>http://localhost/x</a>',
            $extension->linkify('http://localhost/x'),
        );
    }
}
