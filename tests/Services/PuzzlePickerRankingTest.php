<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use SpeedPuzzling\Web\Results\AutocompletePuzzle;
use SpeedPuzzling\Web\Services\PuzzleChoicesBuilder;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The puzzle picker ranks what PuzzleChoicesBuilder sends with Tom Select's own scoring under the form types'
 * PuzzleChoicesBuilder::SEARCH_FIELDS. Runs the real library under node: a puzzle with many names must not rank below
 * one with a single name for the main title both carry.
 */
final class PuzzlePickerRankingTest extends KernelTestCase
{
    public function testTypedMainTitleRanksEveryPuzzleOfThatTitleAlikeWhateverItsNames(): void
    {
        [$exact, $prefix] = $this->search(['Seashells', 'seash']);

        foreach ([$exact, $prefix] as $matches) {
            self::assertSame(['one-name', 'five-names', 'longer-title', 'title-contains', 'circle'], array_column($matches, 'value'));
            self::assertSame($matches[0]['score'], $matches[1]['score']);
            self::assertGreaterThan($matches[2]['score'], $matches[1]['score']);
        }
    }

    public function testExactOtherNameBeatsALongerMainTitleStartingWithIt(): void
    {
        [$matches] = $this->search(['musle']);

        self::assertSame('one-name', $matches[0]['value']);
        self::assertSame('czech-title', $matches[1]['value']);
        self::assertContains('five-names', array_column($matches, 'value'));
    }

    public function testCodesAndPiecesCountAreSearchedToo(): void
    {
        [$barcode, $brandCode, $titleAndPieces, $otherNameOfAnotherBox] = $this->search(['4005556147090', '14709', 'seashells 500', 'Kruh barev']);

        self::assertSame(['five-names'], array_column($barcode, 'value'));
        self::assertSame(['five-names'], array_column($brandCode, 'value'));
        self::assertSame(['one-name', 'circle'], array_column($titleAndPieces, 'value'));
        self::assertSame(['circle'], array_column($otherNameOfAnotherBox, 'value'));
    }

    /**
     * @param list<string> $queries
     *
     * @return list<list<array{value: string, score: float|int}>>
     */
    private function search(array $queries): array
    {
        $options = self::getContainer()->get(PuzzleChoicesBuilder::class)->build([
            self::puzzle('one-name', 'Seashells', ['Mušle'], piecesCount: 500),
            self::puzzle('five-names', 'Seashells', ['Mušle', 'Muscheln', 'Coquillages', 'Conchas', '貝殻'], ean: '4005556147090', code: '14709'),
            self::puzzle('longer-title', 'Seashells at Sunset', ['Mušle při západu slunce']),
            self::puzzle('title-contains', 'Sunset Seashells'),
            self::puzzle('czech-title', 'Mušle a ulity'),
            self::puzzle('circle', 'Circle of Colors: Seashells', ['Kruh barev: Mušle'], piecesCount: 500),
        ], 'en');

        $node = new ExecutableFinder()->find('node');
        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, dirname(__DIR__) . '/puzzle-picker-harness.mjs']);
        $process->setInput(json_encode([
            'options' => $options,
            'fields' => PuzzleChoicesBuilder::SEARCH_FIELDS,
            'queries' => $queries,
        ], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<list<array{value: string, score: float|int}>> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }

    /**
     * @param list<string> $names
     */
    private static function puzzle(string $id, string $name, array $names = [], int $piecesCount = 1000, null|string $ean = null, null|string $code = null): AutocompletePuzzle
    {
        return new AutocompletePuzzle(
            puzzleId: $id,
            puzzleName: $name,
            puzzleAlternativeNames: new PuzzleNames(array_map(static fn (string $other): PuzzleName => new PuzzleName($other, null), $names)),
            puzzleApproved: true,
            manufacturerName: 'Ravensburger',
            piecesCount: $piecesCount,
            puzzleImage: null,
            puzzleImageRatio: null,
            puzzleEan: $ean,
            puzzleIdentificationNumber: $code,
        );
    }
}
