<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Twig\JsonAttributeTwigExtension;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * `data-x={{ value|json_attribute }}` writes the JSON in single quotes: the attribute an HTML parser reads back is the
 * JSON of the value, whatever the strings hold - nothing in it ends the attribute or starts a character reference.
 */
final class JsonAttributeTwigExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed}>
     */
    public static function values(): iterable
    {
        yield 'links' => [['library' => '/en/puzzle-library/018d0000-0000-0000-0000-000000000005', 'all' => '/en/puzzle']];
        yield 'quotes and ampersands' => [['label' => "l'Inbox \"new\" & &amp; &quot;", 'href' => '/en/messages?tab=requests&x=1']];
        yield 'markup' => [['a' => "' onmouseover='alert(1)", 'b' => '</a><script>alert(1)</script><!--']];
        yield 'non-ASCII' => [['ja' => 'メニュー', 'cs' => 'Čeština', 'path' => '/ja/%E3%83%A1']];
        yield 'a list' => [['/menu', '/en/menu', '/de/menue']];
        yield 'nested' => [['none' => ['message' => 'Inbox', 'locale' => 'en'], 'n' => 3, 'empty' => []]];
    }

    #[DataProvider('values')]
    public function testTheAttributeReadsBackAsTheValue(mixed $value): void
    {
        $twig = new Environment(new ArrayLoader(['t' => '<a href="#" data-x={{ value|json_attribute }} data-y="after">x</a>']));
        $twig->addExtension(new JsonAttributeTwigExtension());
        $html = $twig->render('t', ['value' => $value]);

        $link = (new Crawler('<!DOCTYPE html><html><body>' . $html . '</body></html>'))->filter('a');
        self::assertCount(1, $link);
        self::assertSame($value, json_decode((string) $link->attr('data-x'), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('after', $link->attr('data-y'), 'The attribute ends where it should');
        self::assertSame('x', $link->text());
    }

    public function testNoQuoteEntityAndNothingThatEndsTheValue(): void
    {
        $encoded = JsonAttributeTwigExtension::encode(['a' => "it's <b>&</b>", 'b' => '"']);

        self::assertSame("'{\"a\":\"it\\u0027s \\u003Cb\\u003E\\u0026\\u003C/b\\u003E\",\"b\":\"\\\"\"}'", $encoded);
        self::assertSame(2, substr_count($encoded, "'"));
        self::assertStringNotContainsString('&', $encoded);
    }
}
