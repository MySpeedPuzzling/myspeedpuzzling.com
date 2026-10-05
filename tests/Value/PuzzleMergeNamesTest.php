<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\NamedPuzzle;
use SpeedPuzzling\Web\Value\PuzzleMergeNames;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;

final class PuzzleMergeNamesTest extends TestCase
{
    private const string SURVIVOR = '019e0000-0000-7000-8000-000000000001';
    private const string MERGED = '019e0000-0000-7000-8000-000000000002';

    public function testEveryNameStaysTheOtherNamesBeforeTheMainTitles(): void
    {
        $names = new PuzzleMergeNames(
            new NamedPuzzle(self::SURVIVOR, 'Pixar - Epic Animation Gallery', null, new PuzzleNames([new PuzzleName('Survivor box', null)])),
            [new NamedPuzzle(self::MERGED, 'Disney Pixar: Kouzelná cesta', null, new PuzzleNames([new PuzzleName('Pixar Galerie', 'de')]))],
        );

        self::assertSame([
            ['name' => 'Survivor box', 'language' => null],
            ['name' => 'Pixar Galerie', 'language' => 'de'],
            ['name' => 'Disney Pixar: Kouzelná cesta', 'language' => null],
            ['name' => 'Pixar - Epic Animation Gallery', 'language' => null],
        ], $names->alternativeNames()->toArray());
    }

    public function testTheReporterSaysWhichLanguageAMainTitleIsIn(): void
    {
        $names = new PuzzleMergeNames(
            new NamedPuzzle(self::SURVIVOR, 'Pixar - Epic Animation Gallery', null, new PuzzleNames()),
            [new NamedPuzzle(self::MERGED, 'Disney Pixar: Kouzelná cesta', 'sk', new PuzzleNames())],
            [self::MERGED => 'cs'],
        );

        // The reporter's language wins over the one the puzzle had
        self::assertSame([
            ['name' => 'Disney Pixar: Kouzelná cesta', 'language' => 'cs'],
            ['name' => 'Pixar - Epic Animation Gallery', 'language' => null],
        ], $names->alternativeNames()->toArray());
        self::assertSame('cs', $names->nameLanguageOf('Disney Pixar: Kouzelná cesta'));
        self::assertNull($names->nameLanguageOf('Pixar - Epic Animation Gallery'));
    }

    public function testTheMainTitlesLanguageComesFromTheNameItIs(): void
    {
        $names = new PuzzleMergeNames(
            new NamedPuzzle(self::SURVIVOR, 'Kouzelné ráno', 'cs', new PuzzleNames()),
            [new NamedPuzzle(self::MERGED, 'Puzzle 5', null, new PuzzleNames([
                new PuzzleName('Magischer Morgen', 'de'),
                new PuzzleName('Magic Morning', 'en'),
            ]))],
        );

        self::assertSame('de', $names->nameLanguageOf('Magischer Morgen'));
        // English is no language of a main title - null is English
        self::assertNull($names->nameLanguageOf('Magic Morning'));
        // A title none of them has keeps the survivor's language
        self::assertSame('cs', $names->nameLanguageOf('Typed by the reviewer'));
    }

    public function testTheReviewStartsFromTheSurvivorsMainTitleInTheReportersLanguage(): void
    {
        $names = new PuzzleMergeNames(
            new NamedPuzzle(self::SURVIVOR, 'Ledové království', null, new PuzzleNames([new PuzzleName('Frozen', null)])),
            [new NamedPuzzle(self::MERGED, 'Frozen', 'en', new PuzzleNames([new PuzzleName('Die Eiskönigin', 'de')]))],
            [self::SURVIVOR => 'cs'],
        );

        $review = $names->forReview();

        self::assertSame(self::SURVIVOR, $review->puzzleId);
        self::assertSame('Ledové království', $review->name);
        self::assertSame('cs', $review->nameLanguage);
        // Its own main title is not repeated; "Frozen" twice is one name, the tagged one kept
        self::assertSame([
            ['name' => 'Frozen', 'language' => 'en'],
            ['name' => 'Die Eiskönigin', 'language' => 'de'],
        ], $review->alternativeNames->toArray());
    }
}
