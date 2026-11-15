<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use SpeedPuzzling\Web\Services\PuzzleTextSearch;
use SpeedPuzzling\Web\Tests\Services\DataImport\ImportPairingScenario;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleSearchKeys;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The batch lookups of PuzzleTextSearch split the stored search keys into their lines in SQL and compare them by
 * equality with texts written in PHP. Both sides must be the lines PuzzleSearchKeys builds - a key format that
 * changes without them turns this red before a pairing silently finds nothing.
 */
final class PuzzleTextSearchLinesTest extends KernelTestCase
{
    use ImportPairingScenario;

    public function testTheKeysAreSplitIntoExactlyTheLinesTheyAreBuiltOf(): void
    {
        self::bootKernel();

        $names = new PuzzleNames([new PuzzleName('Zq Kouzelná  zahrada', 'cs'), new PuzzleName('Zq Where’s the Garden?', null)]);
        // Two barcodes (one with leading zeros), a catalogue number in the EAN field, two brand codes - one of them
        // the article number its barcode is built from, so the key holds it with the check digit too
        $railway = self::ean('299999154321');
        $eans = [$railway, '00' . self::ean('000123456789'), 'RB-CAT 77'];
        $brandCodes = ['54 321', 'ab-900x'];
        $puzzleId = $this->seedCataloguePuzzle('Zq Magic   GARDEN: Évening', 500, eans: $eans, brandCodes: $brandCodes, otherNames: $names);

        $builtNames = self::lines(PuzzleSearchKeys::names('Zq Magic   GARDEN: Évening', $names));
        $builtCodes = self::lines((string) PuzzleSearchKeys::codes(EanList::fromInputs($eans)->toStored(), BrandCodeList::fromInputs($brandCodes)->toStored()));
        self::assertCount(3, $builtNames);
        self::assertGreaterThanOrEqual(6, count($builtCodes), 'two barcodes, a catalogue number, two brand codes and a check-digit alias');

        $nameLines = $this->pairingDatabase()->fetchFirstColumn('SELECT line FROM (' . PuzzleTextSearch::sqlNameLines() . ') AS lines WHERE id = :id ORDER BY line', ['id' => $puzzleId]);
        sort($builtNames, SORT_STRING);
        self::assertSame($builtNames, $nameLines);

        $codeLines = $this->pairingDatabase()->fetchFirstColumn("SELECT tag || ':' || code FROM (" . PuzzleTextSearch::sqlCodeLines() . ') AS lines WHERE id = :id ORDER BY 1', ['id' => $puzzleId]);
        sort($builtCodes, SORT_STRING);
        self::assertSame($builtCodes, $codeLines);

        // What PHP writes for the other side of the join is one of those lines
        self::assertContains(PuzzleTextSearch::nameLine('ZQ MAGIC GARDEN: EVENING'), $nameLines);
        self::assertContains(PuzzleTextSearch::nameLine('zq kouzelna zahrada'), $nameLines);
        self::assertContains(PuzzleTextSearch::nameLine('Zq Where\'s the Garden?'), $nameLines);
        self::assertContains('e:' . PuzzleTextSearch::barcodeLine('0' . $railway), $codeLines);
        self::assertContains('e:' . PuzzleTextSearch::barcodeLine(self::ean('000123456789')), $codeLines);
        self::assertContains('c:' . PuzzleTextSearch::brandCodeLine('54321'), $codeLines);
        self::assertContains('c:' . PuzzleTextSearch::brandCodeLine('54 321 ' . substr($railway, -1)), $codeLines);
        self::assertContains('c:' . PuzzleTextSearch::brandCodeLine('AB 900 X'), $codeLines);
        self::assertContains('c:' . PuzzleTextSearch::brandCodeLine('rb-cat 77'), $codeLines);

        self::assertNull(PuzzleTextSearch::barcodeLine('not a number'));
        self::assertNull(PuzzleTextSearch::brandCodeLine(' - '));
        self::assertSame('', PuzzleTextSearch::nameLine("  \u{200B} "));
    }

    /**
     * @return list<string>
     */
    private static function lines(string $key): array
    {
        return array_values(array_filter(explode("\n", $key), static fn (string $line): bool => $line !== ''));
    }
}
