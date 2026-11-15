<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\DataImport\SheetLookup;

/**
 * Brand and pieces of a results row from the workbook's puzzle list, by the puzzle's name. Every puzzle is made up.
 */
final class SheetLookupTest extends TestCase
{
    public function testAPuzzleIsFoundByItsNameHoweverItIsWritten(): void
    {
        $lookup = new SheetLookup();
        $lookup->add('Circle of Kites', 'Ravensburger', 500);

        self::assertSame(['brand' => 'Ravensburger', 'pieces' => 500], $lookup->find('  circle of KITES '));
        self::assertSame(['brand' => 'Ravensburger', 'pieces' => 500], $lookup->find('Circle of Kités'));
        self::assertNull($lookup->find('Circle of Kites 2'));
        self::assertFalse($lookup->isAmbiguous('Circle of Kites'));
    }

    public function testANameOnTheListTwiceWithAnotherBrandOrCountIsNoAnswer(): void
    {
        $lookup = new SheetLookup();
        $lookup->add('Twin Bridges', 'Schmidt', 500);
        $lookup->add('Twin Bridges', 'Schmidt', 1000);
        $lookup->add('Tea House', 'Trefl', 500);
        $lookup->add('Tea House', 'Jumbo', 500);

        self::assertNull($lookup->find('Twin Bridges'));
        self::assertTrue($lookup->isAmbiguous('twin bridges'));
        self::assertTrue($lookup->isAmbiguous('Tea House'));
        // Still a name of the list
        self::assertTrue($lookup->has('Twin Bridges'));
    }

    public function testTheSamePuzzleListedTwiceAlikeIsOneAndFillsWhatARowLacks(): void
    {
        $lookup = new SheetLookup();
        $lookup->add('Candy Jars', 'Galison', null);
        $lookup->add('Candy Jars', 'galison ', 300);
        $lookup->add('Candy Jars', null, 300);

        self::assertFalse($lookup->isAmbiguous('Candy Jars'));
        self::assertSame(['brand' => 'Galison', 'pieces' => 300], $lookup->find('Candy Jars'));
        self::assertSame(1, $lookup->count());
    }

    public function testARowThatSaysNothingIsNotOnTheList(): void
    {
        $lookup = new SheetLookup();
        $lookup->add('Only a name', null, null);
        $lookup->add('', 'Trefl', 500);

        self::assertSame(0, $lookup->count());
    }

    public function testCoverageIsTheShareOfNamesTheListHolds(): void
    {
        $lookup = new SheetLookup();
        $lookup->add('Circle of Kites', 'Ravensburger', 500);
        $lookup->add('Tea House', 'Trefl', 500);

        self::assertSame(0.5, $lookup->coverage(['Circle of Kites', 'tea house', 'Unlisted Windmill', 'Another one', '']));
        self::assertSame(0.0, $lookup->coverage([]));
    }
}
