<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\FindPuzzlesByScannedEan;
use SpeedPuzzling\Web\Query\GetMultiscanCandidates;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Tests\ChangesPuzzleRecords;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\Ean;
use SpeedPuzzling\Web\Value\EanParityMisread;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A misread scan (Android's parity misread, README §3.3) finds the puzzle of the code it was misread from - only when
 * exactly one possible original is in the catalogue; a code found as scanned is never corrected.
 */
final class FindPuzzlesByScannedEanTest extends KernelTestCase
{
    use ChangesPuzzleRecords;

    // EAN_PUZZLE_300 (4005556202027) with its G digits read as L ones
    private const string MISREAD_OF_PUZZLE_300 = '0045570202027';
    // EAN_SHARED_4000_5000 (4005556999996), misread the same way
    private const string MISREAD_OF_SHARED_4000_5000 = '0045570999996';

    private FindPuzzlesByScannedEan $find;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->find = self::getContainer()->get(FindPuzzlesByScannedEan::class);
    }

    public function testACodeFoundAsScannedIsNotCorrected(): void
    {
        $lookup = $this->find->byEan(PuzzleFixture::EAN_PUZZLE_300);

        self::assertSame([PuzzleFixture::PUZZLE_300], self::ids($lookup->puzzles));
        self::assertNull($lookup->correctedEan);
    }

    public function testAMisreadFindsThePuzzleOfTheCodeOnTheBox(): void
    {
        $lookup = $this->find->byEan(self::MISREAD_OF_PUZZLE_300);

        self::assertSame([PuzzleFixture::PUZZLE_300], self::ids($lookup->puzzles));
        self::assertSame(PuzzleFixture::EAN_PUZZLE_300, $lookup->correctedEan);

        // As the catalogue stores codes, without the leading zeros
        self::assertSame([PuzzleFixture::PUZZLE_300], self::ids($this->find->byEan(ltrim(self::MISREAD_OF_PUZZLE_300, '0'))->puzzles));
    }

    public function testAMisreadOfASharedCodeFindsEveryPuzzleCarryingIt(): void
    {
        $lookup = $this->find->byEan(self::MISREAD_OF_SHARED_4000_5000);

        self::assertEqualsCanonicalizing([PuzzleFixture::PUZZLE_4000, PuzzleFixture::PUZZLE_5000], self::ids($lookup->puzzles));
        self::assertSame(PuzzleFixture::EAN_SHARED_4000_5000, $lookup->correctedEan);
    }

    public function testTwoPossibleOriginalsInTheCatalogueFindNothing(): void
    {
        $other = array_values(array_filter(
            EanParityMisread::possibleOriginals(self::MISREAD_OF_PUZZLE_300),
            static fn (string $code): bool => $code !== PuzzleFixture::EAN_PUZZLE_300,
        ))[0];
        self::changePuzzleEan(PuzzleFixture::PUZZLE_9000, $other);

        $lookup = $this->find->byEan(self::MISREAD_OF_PUZZLE_300);

        self::assertSame([], $lookup->puzzles);
        self::assertNull($lookup->correctedEan);
    }

    public function testAnUnknownCodeFindsNothing(): void
    {
        $lookup = $this->find->byEan(PuzzleFixture::EAN_UNKNOWN);

        self::assertSame([], $lookup->puzzles);
        self::assertNull($lookup->correctedEan);

        // A code starting with 0 whose possible originals are not in the catalogue
        self::assertSame([], $this->find->byEan('0036000291452')->puzzles);
    }

    public function testMultiscanFindsTheMisreadPuzzle(): void
    {
        $candidates = self::getContainer()->get(GetMultiscanCandidates::class);

        self::assertSame([PuzzleFixture::PUZZLE_300], self::ids($candidates->forEan(Ean::from(self::MISREAD_OF_PUZZLE_300))));
    }

    /**
     * @param list<PuzzleOverview> $puzzles
     * @return list<string>
     */
    private static function ids(array $puzzles): array
    {
        return array_map(static fn (PuzzleOverview $puzzle): string => $puzzle->puzzleId, $puzzles);
    }
}
