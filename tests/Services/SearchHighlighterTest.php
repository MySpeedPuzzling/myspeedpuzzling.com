<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Services\SearchHighlighter;
use PHPUnit\Framework\TestCase;

final class SearchHighlighterTest extends TestCase
{
    #[DataProvider('provideTestHighlightData')]
    public function testHighlight(null|int|string $text, string $search, string $expected): void
    {
        $highlighter = new SearchHighlighter();

        $this->assertSame($expected, $highlighter->highlight($text, $search));
    }

    /**
     * @return \Generator<array{null|int|string, string, string}>
     */
    public static function provideTestHighlightData(): \Generator
    {
        yield ['', '', ''];
        yield [null, 'are', ''];
        yield [1234, 'are', '1234'];
        yield [1234, '23', '1<span class="search-highlight">23</span>4'];
        yield ['Karen', 'are', 'K<span class="search-highlight">are</span>n'];
        yield ['KAREN', 'are', 'K<span class="search-highlight">ARE</span>N'];
        yield ['karen', 'ARE', 'k<span class="search-highlight">are</span>n'];
        yield ['Karen Puzzle', 'kar puz', '<span class="search-highlight">Kar</span>en <span class="search-highlight">Puz</span>zle'];
        yield ['KAREN PUZZLE', 'kar puz', '<span class="search-highlight">KAR</span>EN <span class="search-highlight">PUZ</span>ZLE'];
        yield ['karen puzzle', 'kAR pUZ', '<span class="search-highlight">kar</span>en <span class="search-highlight">puz</span>zle'];
        yield ['Trňas', 'trn', '<span class="search-highlight">Trň</span>as'];
        yield ['Paní Trňasová', 'ani trn', 'P<span class="search-highlight">aní</span> <span class="search-highlight">Trň</span>asová'];
        yield ['Pani Trnasova', 'aní trň', 'P<span class="search-highlight">ani</span> <span class="search-highlight">Trn</span>asova'];
        yield ['Home Home', 'ho h', '<span class="search-highlight">Ho</span>me <span class="search-highlight">Ho</span>me'];
    }
}
