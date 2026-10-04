<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\SearchText;

final class SearchTextTest extends TestCase
{
    #[DataProvider('folds')]
    public function testFold(string $text, string $expected): void
    {
        self::assertSame($expected, SearchText::fold($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function folds(): iterable
    {
        yield 'Polish' => ['Łódź', 'lodz'];
        yield 'German sharp s' => ['Straße', 'strasse'];
        yield 'capital sharp s' => ['GROẞE', 'grosse'];
        yield 'Danish' => ['Ørsted', 'orsted'];
        yield 'Czech' => ['Kouzelná Zahrada', 'kouzelna zahrada'];
        yield 'French' => ['Cœur à Paris', 'coeur a paris'];
        yield 'decomposed accents' => ["Kouzelna\u{0301}", 'kouzelna'];
        yield 'ligature' => ['ﬁsh', 'fish'];
        yield 'full-width wildcards and digits before any escaping' => ['％＿＼４Ａ', '%_\\4a'];
        yield 'half-width katakana' => ['ｶﾀｶﾅ', 'カタカナ'];
        yield 'Japanese kept' => ['猫の時間', '猫の時間'];
        yield 'Cyrillic kept, lower case' => ['Москва', 'москва'];
        yield 'Greek kept, lower case' => ['ΑΘΗΝΑ', 'αθηνα'];
        yield 'tabs, newlines and double spaces' => ["  Sea\t\tshells \n\r of   Bali  ", 'sea shells of bali'];
        yield 'no-break and ideographic spaces' => ["Sea\u{00A0}shells\u{3000}x", 'sea shells x'];
        yield 'zero-width and soft hyphen removed' => ["Sea\u{200B}shells\u{00AD}!", 'seashells!'];
        yield 'control characters removed' => ["A\u{0000}B\u{0007}C", 'abc'];
        yield 'punctuation kept' => ['Wasgij? Mystery #12: Café', 'wasgij? mystery #12: cafe'];
        yield 'empty string' => ['', ''];
        yield 'only whitespace' => [" \t\n ", ''];
        yield 'invalid UTF-8 does not fail' => ["Caf\xE9", 'caf?'];
    }

    public function testFoldedTextNeverHoldsANewline(): void
    {
        self::assertStringNotContainsString("\n", SearchText::fold("First line\nSecond line\u{2028}Third\u{2029}"));
    }

    public function testFoldIsIdempotent(): void
    {
        $once = SearchText::fold('Straße ﬁsh Łódź ｶﾀｶﾅ ％Ａ');

        self::assertSame($once, SearchText::fold($once));
    }
}
