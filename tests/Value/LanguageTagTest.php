<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\LanguageTag;

final class LanguageTagTest extends TestCase
{
    #[DataProvider('tags')]
    public function testNormalize(string $tag, null|string $expected): void
    {
        self::assertSame($expected, LanguageTag::normalize($tag));
    }

    /**
     * @return iterable<string, array{string, null|string}>
     */
    public static function tags(): iterable
    {
        yield 'base language' => ['cs', 'cs'];
        yield 'upper case base' => ['DE', 'de'];
        yield 'surrounding spaces' => ['  ja ', 'ja'];
        yield 'region' => ['pt-br', 'pt-BR'];
        yield 'underscore' => ['pt_BR', 'pt-BR'];
        yield 'script' => ['zh-hant', 'zh-Hant'];
        yield 'script and region' => ['ZH_HANT_tw', 'zh-Hant-TW'];
        yield 'numeric region' => ['es-419', 'es-419'];
        yield 'variant' => ['de-CH-1996', 'de-CH-1996'];
        yield 'three letter language' => ['fil', 'fil'];
        yield 'unknown language' => ['xx', null];
        yield 'unknown language with region' => ['qq-CZ', null];
        yield 'empty' => ['', null];
        yield 'no tag' => ['Czech', null];
        yield 'invalid characters' => ['cs CZ', null];
        yield 'empty subtag' => ['cs--CZ', null];
        yield 'too long for the column' => ['de-CH-1996-fonipa-x', null];
    }

    public function testBase(): void
    {
        self::assertSame('pt', LanguageTag::base('pt-BR'));
        self::assertSame('zh', LanguageTag::base('ZH_Hant'));
        self::assertSame('cs', LanguageTag::base(' cs '));
    }
}
