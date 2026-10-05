<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;

final class PuzzleTest extends TestCase
{
    public function testConstructionCleansTheNamesAndBuildsBothKeys(): void
    {
        $puzzle = new Puzzle(
            id: Uuid::uuid7(),
            piecesCount: 500,
            name: "  Circle of Colors:\tSeashells ",
            approved: false,
            alternativeNames: new PuzzleNames([
                new PuzzleName('Muscheln', 'de'),
                new PuzzleName('CIRCLE OF COLORS: SEASHELLS', null),
                new PuzzleName(' Kruh barev: Mušle', 'CS'),
                new PuzzleName('Kruh barev: Musle', null),
            ]),
            brandCodes: BrandCodeList::fromStored('14709'),
            eans: EanList::fromStored('04005556147090'),
            nameLanguage: 'PT-br',
        );

        self::assertSame('Circle of Colors: Seashells', $puzzle->name);
        self::assertSame('pt-BR', $puzzle->nameLanguage);
        self::assertSame([
            ['name' => 'Muscheln', 'language' => 'de'],
            ['name' => 'Kruh barev: Mušle', 'language' => 'cs'],
        ], $puzzle->alternativeNames);
        self::assertSame("\ncircle of colors: seashells\nmuscheln\nkruh barev: musle\n", $puzzle->searchNames);
        self::assertSame('4005556147090', $puzzle->ean, 'stored in the canonical form');
        self::assertSame("\ne:4005556147090\nc:14709\nc:147090\n", $puzzle->searchCodes);
        self::assertNull($puzzle->namesChangedAt);
    }

    public function testAPuzzleWithoutOtherNamesOrCodes(): void
    {
        $puzzle = new Puzzle(id: Uuid::uuid7(), piecesCount: 500, name: 'Seashells', approved: true);

        self::assertSame([], $puzzle->alternativeNames);
        self::assertTrue($puzzle->alternativeNames()->isEmpty());
        self::assertNull($puzzle->nameLanguage);
        self::assertSame("\nseashells\n", $puzzle->searchNames);
        self::assertNull($puzzle->searchCodes);
    }

    public function testChangeNamesRebuildsTheKey(): void
    {
        $puzzle = new Puzzle(id: Uuid::uuid7(), piecesCount: 500, name: 'Seashells', approved: true);
        $now = new DateTimeImmutable('2026-10-04 12:00:00');

        $puzzle->changeNames('Circle of Colors: Seashells', null, new PuzzleNames([
            new PuzzleName('Seashells', 'en'),
            new PuzzleName('貝殻', 'ja'),
            new PuzzleName('Mušle', 'cs'),
        ]), $now);

        self::assertSame('Circle of Colors: Seashells', $puzzle->name);
        self::assertSame([
            ['name' => 'Seashells', 'language' => 'en'],
            ['name' => '貝殻', 'language' => 'ja'],
            ['name' => 'Mušle', 'language' => 'cs'],
        ], $puzzle->alternativeNames);
        self::assertSame("\ncircle of colors: seashells\nseashells\n貝殻\nmusle\n", $puzzle->searchNames);
        self::assertEquals($now, $puzzle->namesChangedAt);
    }

    public function testEnglishIsNeverStoredAsTheMainTitlesLanguage(): void
    {
        $puzzle = new Puzzle(id: Uuid::uuid7(), piecesCount: 500, name: 'Seashells', approved: true, nameLanguage: 'en');
        self::assertNull($puzzle->nameLanguage);

        foreach (['en', 'EN-gb', 'en-US', 'en_AU'] as $english) {
            $puzzle->changeNames('Seashells', 'cs', new PuzzleNames(), new DateTimeImmutable());
            self::assertSame('cs', $puzzle->nameLanguage);

            $puzzle->changeNames('Seashells', $english, new PuzzleNames(), new DateTimeImmutable());
            self::assertNull($puzzle->nameLanguage, $english . ' is English: null');
        }

        // An other name keeps its English tag
        $puzzle->changeNames('Kruh barev', 'cs', new PuzzleNames([new PuzzleName('Circle of Colors', 'en-GB')]), new DateTimeImmutable());
        self::assertSame([['name' => 'Circle of Colors', 'language' => 'en-GB']], $puzzle->alternativeNames);
    }

    public function testNamesChangedAtMovesOnlyOnARealChange(): void
    {
        $puzzle = new Puzzle(
            id: Uuid::uuid7(),
            piecesCount: 500,
            name: 'Seashells',
            approved: true,
            alternativeNames: new PuzzleNames([new PuzzleName('Mušle', 'cs')]),
        );
        $first = new DateTimeImmutable('2026-10-01');

        // Only whitespace and a duplicate that folds away: the same names
        $puzzle->changeNames(' Seashells ', null, new PuzzleNames([new PuzzleName('Mušle ', 'cs'), new PuzzleName('musle', null)]), $first);
        self::assertNull($puzzle->namesChangedAt);

        // English is no language of a main title - still the same names
        $puzzle->changeNames('Seashells', 'en', $puzzle->alternativeNames(), $first);
        self::assertNull($puzzle->namesChangedAt);

        $puzzle->changeNames('Seashells', 'de', $puzzle->alternativeNames(), $first);
        self::assertEquals($first, $puzzle->namesChangedAt);

        $puzzle->changeNames('Seashells', 'de', $puzzle->alternativeNames(), new DateTimeImmutable('2026-10-02'));
        self::assertEquals($first, $puzzle->namesChangedAt);

        $puzzle->changeNames('Seashells', 'de', new PuzzleNames([new PuzzleName('Mušle', 'sk')]), new DateTimeImmutable('2026-10-03'));
        self::assertEquals(new DateTimeImmutable('2026-10-03'), $puzzle->namesChangedAt);
    }

    public function testAnEmptyMainTitleIsRefusedAndNothingChanges(): void
    {
        $puzzle = new Puzzle(id: Uuid::uuid7(), piecesCount: 500, name: 'Seashells', approved: true);

        try {
            $puzzle->changeNames(" \u{200B} ", null, new PuzzleNames([new PuzzleName('Mušle', 'cs')]), new DateTimeImmutable());
            self::fail('An empty name must be refused');
        } catch (InvalidPuzzleValues) {
        }

        self::assertSame('Seashells', $puzzle->name);
        self::assertSame([], $puzzle->alternativeNames);
        self::assertNull($puzzle->namesChangedAt);
    }

    public function testAMainTitleLongerThan255CharactersIsRefused(): void
    {
        $this->expectException(InvalidPuzzleValues::class);

        new Puzzle(id: Uuid::uuid7(), piecesCount: 500, name: str_repeat('ř', 256), approved: true);
    }

    public function testTheEntityDoesNotCapTheNumberOfNames(): void
    {
        $names = array_map(static fn (int $i): PuzzleName => new PuzzleName('Name ' . $i, null), range(1, 30));
        $puzzle = new Puzzle(id: Uuid::uuid7(), piecesCount: 500, name: 'Seashells', approved: true, alternativeNames: new PuzzleNames($names));

        self::assertCount(30, $puzzle->alternativeNames);
    }

    public function testUpdateProductIdentifiersRebuildsTheCodesKey(): void
    {
        $puzzle = new Puzzle(id: Uuid::uuid7(), piecesCount: 500, name: 'Seashells', approved: true, eans: EanList::fromStored('4005556147090'));

        $puzzle->updateProductIdentifiers(
            EanList::fromInputs(['4005556147090', ' 0 4005555 001997 ', '']),
            BrandCodeList::fromInputs(['rb-147', 'RB-147']),
        );

        self::assertSame('4005556147090, 4005555001997', $puzzle->ean);
        self::assertSame('RB-147', $puzzle->identificationNumber);
        self::assertSame("\ne:4005556147090\ne:4005555001997\nc:rb147\n", $puzzle->searchCodes);

        $puzzle->updateProductIdentifiers(EanList::fromInputs([]), BrandCodeList::fromInputs(['']));
        self::assertNull($puzzle->ean);
        self::assertNull($puzzle->identificationNumber);
        self::assertNull($puzzle->searchCodes);
    }

    public function testCodesPassedOnUnchangedKeepTheirStoredForm(): void
    {
        $puzzle = new Puzzle(id: Uuid::uuid7(), piecesCount: 500, name: 'Seashells', approved: true);
        // As typed before the lists (a row of an older release)
        $puzzle->ean = '0091683108909, 6000-5468';
        $puzzle->identificationNumber = 'Clementoni, rb 14709';

        // A name suggestion, a field not approved: the same codes, read and passed on
        $puzzle->updateProductIdentifiers($puzzle->eans(), BrandCodeList::fromInputs(['CLEMENTONI', 'RB  14709']));

        self::assertSame('0091683108909, 6000-5468', $puzzle->ean);
        self::assertSame('Clementoni, rb 14709', $puzzle->identificationNumber);

        // A list that changes is stored in its canonical form - a number that is no barcode as typed
        $puzzle->updateProductIdentifiers(EanList::fromInputs(['091683108909', '6000-5468', '4005556147090']), $puzzle->brandCodes());

        self::assertSame('91683108909, 6000-5468, 4005556147090', $puzzle->ean);
        self::assertSame('Clementoni, rb 14709', $puzzle->identificationNumber);
    }

    public function testCanonicalizingWritesTheCanonicalFormOfTheChosenLists(): void
    {
        $puzzle = new Puzzle(id: Uuid::uuid7(), piecesCount: 500, name: 'Seashells', approved: true);
        $puzzle->ean = '0091683108909';
        $puzzle->identificationNumber = 'rb 14709';

        $puzzle->canonicalizeProductIdentifiers(eans: true, brandCodes: false);

        self::assertSame('91683108909', $puzzle->ean);
        self::assertSame('rb 14709', $puzzle->identificationNumber);
        self::assertSame("\ne:91683108909\nc:rb14709\n", $puzzle->searchCodes);
    }

    public function testCorrectNewlyAddedGoesThroughTheNamesAndCodes(): void
    {
        $puzzle = new Puzzle(id: Uuid::uuid7(), piecesCount: 500, name: 'Seashels', approved: false);
        $manufacturer = $this->createStub(Manufacturer::class);
        $now = new DateTimeImmutable('2026-10-04');

        $puzzle->correctNewlyAdded(
            name: 'Seashells',
            alternativeNames: new PuzzleNames([new PuzzleName('Mušle', 'cs')]),
            piecesCount: 1000,
            manufacturer: $manufacturer,
            image: 'puzzles/seashells.jpg',
            imageRatio: 1.4,
            eans: EanList::fromStored('4005556147090'),
            brandCodes: BrandCodeList::fromStored('14709'),
            now: $now,
        );

        self::assertSame('Seashells', $puzzle->name);
        self::assertSame([['name' => 'Mušle', 'language' => 'cs']], $puzzle->alternativeNames);
        self::assertSame("\nseashells\nmusle\n", $puzzle->searchNames);
        self::assertSame("\ne:4005556147090\nc:14709\nc:147090\n", $puzzle->searchCodes);
        self::assertSame(1000, $puzzle->piecesCount);
        self::assertEquals($now, $puzzle->namesChangedAt);
    }

    public function testRefreshSearchKeysBuildsMissingKeysAndChangesNothingElse(): void
    {
        $puzzle = new Puzzle(
            id: Uuid::uuid7(),
            piecesCount: 500,
            name: 'Seashells',
            approved: true,
            alternativeNames: new PuzzleNames([new PuzzleName('Mušle', 'cs')]),
            eans: EanList::fromStored('4005556147090'),
        );
        $names = $puzzle->searchNames;
        $codes = $puzzle->searchCodes;

        // What a row written by an older release looks like
        $puzzle->searchNames = null;
        $puzzle->searchCodes = null;

        $puzzle->refreshSearchKeys();

        self::assertSame($names, $puzzle->searchNames);
        self::assertSame($codes, $puzzle->searchCodes);
        self::assertNull($puzzle->namesChangedAt);
        self::assertSame([['name' => 'Mušle', 'language' => 'cs']], $puzzle->alternativeNames);
    }
}
