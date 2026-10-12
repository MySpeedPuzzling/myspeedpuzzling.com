<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\EditionName;

final class EditionNameTest extends TestCase
{
    /**
     * @param list<null|string> $seriesNames
     */
    #[DataProvider('provideNames')]
    public function testWithoutSeries(string $editionName, array $seriesNames, null|string $expected): void
    {
        self::assertSame($expected, EditionName::withoutSeries($editionName, ...$seriesNames));
    }

    /**
     * @return iterable<string, array{string, list<null|string>, null|string}>
     */
    public static function provideNames(): iterable
    {
        yield 'starts with the series name' => ['Euro Jigsaw Jam #168', ['Euro Jigsaw Jam'], '#168'];
        yield 'separator after the series name goes too' => ['Euro Jigsaw Jam - 8 October 2026', ['Euro Jigsaw Jam'], '8 October 2026'];
        yield 'case-insensitive' => ['euro jigsaw jam #165 - UNEVEN Pairs', ['Euro Jigsaw Jam'], '#165 - UNEVEN Pairs'];
        yield 'named like the series' => ['Euro Jigsaw Jam', ['Euro Jigsaw Jam'], null];
        yield 'only the start of a longer word' => ['Euro Jigsaw Jamboree', ['Euro Jigsaw Jam'], 'Euro Jigsaw Jamboree'];
        yield 'the series further in' => ['Spring Euro Jigsaw Jam', ['Euro Jigsaw Jam'], 'Spring Euro Jigsaw Jam'];
        yield 'the shortcut' => ['EJJ #68 — February 2026', ['Euro Jigsaw Jam', 'EJJ'], '#68 — February 2026'];
        yield 'shortcut glued to a number' => ['EJJ2026', ['Euro Jigsaw Jam', 'EJJ'], 'EJJ2026'];
        yield 'no shortcut' => ['Season One', ['Moonlight Sprint League', null], 'Season One'];
    }
}
