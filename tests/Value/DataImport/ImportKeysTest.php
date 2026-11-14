<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value\DataImport;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\DataImport\ImportBrandText;
use SpeedPuzzling\Web\Value\DataImport\ImportKeyFold;
use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleKey;
use SpeedPuzzling\Web\Value\DataImport\ImportSourceKey;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * The keys of the import are stored for ever and compared for ever (docs/features/data-import/README.md "Keys,
 * exactly", "Never twice"; docs/features/data-import/value-readers.md "Keys"). The exact outputs are pinned with
 * literals: a test that turns red here means stored keys would stop matching - because the canonical text changed,
 * the frozen fold was edited, or the ICU of the image folds a character differently. Never "fix" a literal: a new
 * rule is key version 2, computed side by side.
 */
final class ImportKeysTest extends TestCase
{
    private const string COZY_CABIN = '1:bdd6d51ddc483b9e57a5e2e7712ac93bb6dd272a9550e7dab416c95107d6d2c3';

    #[DataProvider('pinnedPuzzleKeys')]
    public function testThePuzzleKeyIsPinned(string $name, null|int $pieces, null|string $brandText, string $canonical, string $key): void
    {
        self::assertSame($canonical, ImportPuzzleKey::canonical($name, $pieces, $brandText));
        self::assertSame($key, ImportPuzzleKey::of($name, $pieces, $brandText));
        self::assertSame($key, '1:' . hash('sha256', $canonical), 'The key is its version and the sha256 of the canonical text');
        self::assertMatchesRegularExpression('/^1:[0-9a-f]{64}$/', $key);
    }

    /**
     * @return iterable<string, array{string, null|int, null|string, string, string}>
     */
    public static function pinnedPuzzleKeys(): iterable
    {
        yield 'name, pieces and a brand' => [
            'Cozy Cabin', 1000, 'Ravensburger',
            "puzzle-key:1\ncozy cabin\n1000\nravensburger",
            self::COZY_CABIN,
        ];
        yield 'a part in brackets stays; a brand of two words is its letters' => [
            'Cozy Cabin (2nd edition)', 1000, 'Master Pieces',
            "puzzle-key:1\ncozy cabin (2nd edition)\n1000\nmasterpieces",
            '1:319e49e176ada2f886e3fbfb492fc3c8def5fd8212ad960d432ecf8707d4f095',
        ];
        yield 'another piece count is another puzzle' => [
            'Cozy Cabin', 500, 'Ravensburger',
            "puzzle-key:1\ncozy cabin\n500\nravensburger",
            '1:bf37ad3ea32048839d9daa25315fb073996d62366498918436286c6d5f5ffdcd',
        ];
        yield 'no pieces and no brand' => [
            'Cozy Cabin', null, null,
            "puzzle-key:1\ncozy cabin\n\n",
            '1:d44a85346fd3d4b5ab4a588e8aa71fd258c1ef6e03398693cbbe5cd18bdd0456',
        ];
        yield 'an apostrophe goes, a question mark stays' => [
            'Where\'s Wally?', 1000, 'Jumbo',
            "puzzle-key:1\nwheres wally?\n1000\njumbo",
            '1:c9eb2cee54a3e82bddd9fd8b1e77103e3b3748a9800b19c00ca872c3d6a87403',
        ];
        yield 'a colon in the name, no brand' => [
            'Circle of Colours: Ocean', 500, null,
            "puzzle-key:1\ncircle of colours: ocean\n500\n",
            '1:2e2511046095e111732e4a6c60122953a34e791f45214272c7e90b70bcc301ee',
        ];
    }

    /**
     * The fold runs on the ICU of the image. These literals were computed on ICU as shipped on 2026-11-14: an image
     * whose ICU folds one of them differently must turn this red before a key is stored with it.
     */
    #[DataProvider('pinnedExoticNames')]
    public function testTheFoldOfExoticCharactersIsPinned(string $name, null|string $brandText, string $canonical, string $key): void
    {
        self::assertSame($canonical, ImportPuzzleKey::canonical($name, 1000, $brandText));
        self::assertSame($key, ImportPuzzleKey::of($name, 1000, $brandText));
    }

    /**
     * @return iterable<string, array{string, null|string, string, string}>
     */
    public static function pinnedExoticNames(): iterable
    {
        $cafe = "puzzle-key:1\ncafe de nuit\n1000\ntrefl";
        $cafeKey = '1:fff12caef147d6675fe08d89e279a8151bd942572ade5a927e07f94db8ac9bbe';

        yield 'an accent as one character (NFC)' => ['Café de nuit', 'Trefl', $cafe, $cafeKey];
        yield 'the same accent as a letter and a combining mark (NFD)' => ["Cafe\u{0301} de nuit", 'Trefl', $cafe, $cafeKey];
        yield 'an en dash stays an en dash' => [
            'Paris – Night', 'Trefl',
            "puzzle-key:1\nparis – night\n1000\ntrefl",
            '1:fa6f993589fe1d59ec3c9a876205933e894632487cc2102d0f8dd84855ea6f7e',
        ];
        yield 'full-width letters and an ideographic space' => [
            'Ｃｏｚｙ　Ｃａｂｉｎ', 'Ｒａｖｅｎｓｂｕｒｇｅｒ',
            "puzzle-key:1\ncozy cabin\n1000\nravensburger",
            self::COZY_CABIN,
        ];
        yield 'ß is ss' => [
            'Straße im Regen', 'Schmidt Spiele',
            "puzzle-key:1\nstrasse im regen\n1000\nschmidtspiele",
            '1:58b28fbb2d478b0ca6d9469fca063f5e6cf93520b1eccea3b2621c758db061f1',
        ];
        yield 'Ł, ó, ź, ą' => [
            'Łódź nocą', 'Trefl',
            "puzzle-key:1\nlodz noca\n1000\ntrefl",
            '1:f96879f3c52d452051653987ae3508ff25841ad55109ca5fface7da6dbde5602',
        ];
        yield 'a Japanese title stays as it is' => [
            '富士山と桜', 'エポック社',
            "puzzle-key:1\n富士山と桜\n1000\nエポック社",
            '1:b932fc2c062c4c254d4f492f2f492f507b0a5772c7a79375d6c482c1517b684b',
        ];
        yield 'half-width katakana becomes full-width' => [
            'ﾊﾟｽﾞﾙ', null,
            "puzzle-key:1\nパズル\n1000\n",
            '1:69287d0d5188cbd988b5079f570ff0f6a4b68f1b7b92635b9d7f653bdb97f72f',
        ];
        yield 'an emoji stays' => [
            'Cats 🐱 & Dogs', 'eeBoo',
            "puzzle-key:1\ncats 🐱 & dogs\n1000\neeboo",
            '1:db33c00d5cf8a65247e73d298f4814e435aad3d1e42027d8c85f88696246b115',
        ];
        yield 'a digraph, a ligature, a numero sign and a fraction' => [
            'ǅungla ﬁnale №5 ½', null,
            "puzzle-key:1\ndzungla finale no5 1⁄2\n1000\n",
            '1:4c1579472610e8e81fd6b6a7f1861b13298ca7f7260cc38f58f0a2bcda3ce6c5',
        ];
    }

    #[DataProvider('samePuzzle')]
    public function testWhatIsTheSamePuzzle(string $name, null|int $pieces, null|string $brandText): void
    {
        self::assertSame(self::COZY_CABIN, ImportPuzzleKey::of($name, $pieces, $brandText));
    }

    /**
     * @return iterable<string, array{string, null|int, null|string}>
     */
    public static function samePuzzle(): iterable
    {
        yield 'upper case' => ['COZY CABIN', 1000, 'RAVENSBURGER'];
        yield 'spaces around and inside' => ['  Cozy   Cabin ', 1000, ' Ravensburger '];
        yield 'a non-breaking space' => ["Cozy\u{00A0}Cabin", 1000, 'Ravensburger'];
        yield 'a line break in the cell' => ["Cozy\nCabin", 1000, 'Ravensburger'];
        yield 'accents nobody meant' => ['Cózy Cabín', 1000, 'Ravensburger'];
        yield 'a direction mark' => ["\u{200E}Cozy Cabin", 1000, 'Ravensburger'];
        yield 'the brand with a dash' => ['Cozy Cabin', 1000, 'Ravens-burger'];
        yield 'the brand in two words' => ['Cozy Cabin', 1000, 'Ravens Burger'];
        yield 'the brand with a sign' => ['Cozy Cabin', 1000, 'Ravensburger®'];
    }

    #[DataProvider('anotherPuzzle')]
    public function testWhatIsAnotherPuzzle(string $name, null|int $pieces, null|string $brandText): void
    {
        self::assertNotSame(self::COZY_CABIN, ImportPuzzleKey::of($name, $pieces, $brandText));
    }

    /**
     * @return iterable<string, array{string, null|int, null|string}>
     */
    public static function anotherPuzzle(): iterable
    {
        yield 'another name' => ['Cosy Cabin', 1000, 'Ravensburger'];
        yield 'a part in brackets' => ['Cozy Cabin (XXL)', 1000, 'Ravensburger'];
        yield 'another piece count' => ['Cozy Cabin', 1001, 'Ravensburger'];
        yield 'no piece count' => ['Cozy Cabin', null, 'Ravensburger'];
        yield 'another brand' => ['Cozy Cabin', 1000, 'Schmidt'];
        yield 'no brand' => ['Cozy Cabin', 1000, null];
        yield 'an empty brand' => ['Cozy Cabin', 1000, ''];
        yield 'punctuation in the name counts' => ['Cozy-Cabin', 1000, 'Ravensburger'];
    }

    public function testAnUnknownPieceCountIsOneThingHoweverItIsWritten(): void
    {
        $unknown = ImportPuzzleKey::of('Cozy Cabin', null, 'Ravensburger');

        self::assertSame($unknown, ImportPuzzleKey::of('Cozy Cabin', 0, 'Ravensburger'));
        self::assertSame($unknown, ImportPuzzleKey::of('Cozy Cabin', -5, 'Ravensburger'));
        self::assertNotSame($unknown, ImportPuzzleKey::of('Cozy Cabin', 1, 'Ravensburger'));
    }

    public function testARowWithoutANameHasNoKey(): void
    {
        foreach (['', '   ', "\u{00A0}", "\u{200B}", '\''] as $name) {
            self::assertNull(ImportPuzzleKey::of($name, 1000, 'Ravensburger'));
            self::assertNull(ImportPuzzleKey::canonical($name, 1000, 'Ravensburger'));
            self::assertNull(ImportPuzzleKey::resolved($name, 1000, '018e4c1f-5a5c-7a3c-9d2e-1f2a3b4c5d6e'));
        }
    }

    public function testTheKeyDoesNotKnowTheCatalogue(): void
    {
        // The brand "MB" before anybody said what it is, after the player's answer, after the brand was created or
        // merged: the row says the same, so its key - and every source key built on it - is the same
        $before = ImportPuzzleKey::of('Cozy Cabin', 1000, 'MB');
        self::assertNotNull($before);

        $resultBefore = ImportSourceKey::result($before, self::day('2023-12-30'), DatePrecision::Day, 4157, PuzzlingType::Solo);
        $ownedBefore = ImportSourceKey::owned($before);

        // … the brand is resolved to a catalogue brand: a second key exists now, the first has not moved
        $resolved = ImportPuzzleKey::resolved('Cozy Cabin', 1000, '018e4c1f-5a5c-7a3c-9d2e-1f2a3b4c5d6e');
        $after = ImportPuzzleKey::of('Cozy Cabin', 1000, 'MB');

        self::assertSame($before, $after);
        self::assertNotNull($resolved);
        self::assertNotSame($before, $resolved);
        self::assertSame($resultBefore, ImportSourceKey::result($after, self::day('2023-12-30'), DatePrecision::Day, 4157, PuzzlingType::Solo));
        self::assertSame($ownedBefore, ImportSourceKey::owned($after));
    }

    public function testTheResolvedKeyIsPinnedAndMeetsAcrossSpellings(): void
    {
        $id = '018e4c1f-5a5c-7a3c-9d2e-1f2a3b4c5d6e';

        self::assertSame("puzzle-key-by-brand:1\ncozy cabin\n1000\n" . $id, ImportPuzzleKey::canonicalResolved('Cozy Cabin', 1000, $id));
        self::assertSame('1:06e44de72c30ab6b00eb62b7cd7aa795d3c73f060c51057bd3a73878d73006d9', ImportPuzzleKey::resolved('Cozy Cabin', 1000, $id));

        // "Ravensburger" and "Ravensburger Spieleverlag" are two text keys and one resolved key
        self::assertNotSame(
            ImportPuzzleKey::of('Cozy Cabin', 1000, 'Ravensburger'),
            ImportPuzzleKey::of('Cozy Cabin', 1000, 'Ravensburger Spieleverlag'),
        );
        self::assertSame(ImportPuzzleKey::resolved('Cozy Cabin', 1000, $id), ImportPuzzleKey::resolved('COZY  CABIN', 1000, ' ' . strtoupper($id) . ' '));

        self::assertNull(ImportPuzzleKey::resolved('Cozy Cabin', 1000, null), 'Without a brand id there is no resolved key');
        self::assertNull(ImportPuzzleKey::resolved('Cozy Cabin', 1000, '  '));
        self::assertNotSame(ImportPuzzleKey::resolved('Cozy Cabin', 1000, $id), ImportPuzzleKey::resolved('Cozy Cabin', 500, $id));
    }

    public function testNoPartCanRunIntoTheNext(): void
    {
        self::assertNotSame(
            ImportPuzzleKey::of('Cabin 1000', null, 'x'),
            ImportPuzzleKey::of('Cabin', 1000, 'x'),
        );
        self::assertNotSame(
            ImportPuzzleKey::of("Cabin\n1000", null, 'x'),
            ImportPuzzleKey::of('Cabin', 1000, 'x'),
            'A line break in a name is a space once folded',
        );
        self::assertNotSame(
            ImportPuzzleKey::of('Cabin', 1000, '018e4c1f-5a5c-7a3c-9d2e-1f2a3b4c5d6e'),
            ImportPuzzleKey::resolved('Cabin', 1000, '018e4c1f-5a5c-7a3c-9d2e-1f2a3b4c5d6e'),
            'A brand text cannot pose as a brand id',
        );
    }

    public function testTheFrozenFoldIsWhatItWas(): void
    {
        // The rules of ImportKeyFold::v1(), one by one - never to be edited
        self::assertSame('wheres wally', ImportKeyFold::v1('Where’s  Wally'));
        self::assertSame('wheres wally', ImportKeyFold::v1('Where´s Wally'));
        self::assertSame('lodz', ImportKeyFold::v1('ŁÓDŹ'));
        self::assertSame('strasse', ImportKeyFold::v1('Straße'));
        self::assertSame('a b', ImportKeyFold::v1(" a\t\n b \u{00A0}"));
        self::assertSame('ab', ImportKeyFold::v1("a\u{200B}b\u{00AD}"));
        self::assertSame('100%_\\4', ImportKeyFold::v1('１００％＿＼４'));
        self::assertSame('パズル', ImportKeyFold::v1('ﾊﾟｽﾞﾙ'));
        self::assertSame('', ImportKeyFold::v1("\u{0301}"));
        self::assertSame('caf?', ImportKeyFold::v1("Caf\xC3"));
        self::assertSame('masterpieces', ImportKeyFold::lettersAndDigitsV1('Master-Pieces ®'));
    }

    #[DataProvider('brands')]
    public function testTheBrandAsLettersAndDigits(null|string $brand, string $expected): void
    {
        self::assertSame($expected, ImportBrandText::lettersAndDigits($brand));
    }

    /**
     * @return iterable<string, array{null|string, string}>
     */
    public static function brands(): iterable
    {
        yield 'the README\'s Master Pieces' => ['Master Pieces', 'masterpieces'];
        yield 'joined' => ['MasterPieces', 'masterpieces'];
        yield 'lower case' => ['masterpieces', 'masterpieces'];
        yield 'a dash' => ['Master-Pieces', 'masterpieces'];
        yield 'a dash and spaces around' => [' D-Toys ', 'dtoys'];
        yield 'an ampersand' => ['Galison & Mudpuppy', 'galisonmudpuppy'];
        yield 'an apostrophe' => ['Pieces\'n\'Peace', 'piecesnpeace'];
        yield 'accents' => ['Éducá', 'educa'];
        yield 'digits' => ['4 Puzzle 2', '4puzzle2'];
        yield 'a sign' => ['Ravensburger®', 'ravensburger'];
        yield 'Japanese' => ['エポック社', 'エポック社'];
        yield 'full-width' => ['ＭＢ', 'mb'];
        yield 'nothing but marks' => ['---', ''];
        yield 'empty' => ['', ''];
        yield 'none' => [null, ''];
    }

    #[DataProvider('pinnedResultKeys')]
    public function testTheKeyOfAResultIsPinned(
        null|string $day,
        DatePrecision $precision,
        null|int $seconds,
        PuzzlingType $type,
        int $occurrence,
        string $canonical,
        string $key,
    ): void {
        $date = $day === null ? null : self::day($day);

        self::assertSame($canonical, ImportSourceKey::canonicalResult(self::COZY_CABIN, $date, $precision, $seconds, $type, $occurrence));
        self::assertSame($key, ImportSourceKey::result(self::COZY_CABIN, $date, $precision, $seconds, $type, $occurrence));
        self::assertSame($key, '1:' . hash('sha256', $canonical));
    }

    /**
     * @return iterable<string, array{null|string, DatePrecision, null|int, PuzzlingType, int, string, string}>
     */
    public static function pinnedResultKeys(): iterable
    {
        $head = "source-key:1\nresult\n" . self::COZY_CABIN . "\n";

        yield 'a day and a time' => [
            '2023-12-30', DatePrecision::Day, 4157, PuzzlingType::Solo, 1,
            $head . "2023-12-30\n4157\nsolo\n1",
            '1:fca62cf6d8676332c5d8adcc3d6baee75d33ee767d57a928e3b0914cce7e72cd',
        ];
        yield 'only the month is known' => [
            '2023-12-30', DatePrecision::Month, 4157, PuzzlingType::Solo, 1,
            $head . "2023-12\n4157\nsolo\n1",
            '1:8e8a7785bea6e733d3fc445a4768b37adc2bd230264fe2acf0e05c162f72367a',
        ];
        yield 'only the year is known' => [
            '2023-12-30', DatePrecision::Year, 4157, PuzzlingType::Solo, 1,
            $head . "2023\n4157\nsolo\n1",
            '1:cde36f76f243d769a73dbffd443a27206f24cd03b37dceec8719c80818e052fd',
        ];
        yield 'no date' => [
            null, DatePrecision::Day, 4157, PuzzlingType::Solo, 1,
            $head . "no date\n4157\nsolo\n1",
            '1:c23ec4b06e4878de2d287cb3763ab16ba97d0e0622a1f132f51071737bd0b4bf',
        ];
        yield 'no time' => [
            '2023-12-30', DatePrecision::Day, null, PuzzlingType::Solo, 1,
            $head . "2023-12-30\nno time\nsolo\n1",
            '1:2bdf80fb858fce26b1dba9730e85176655e92e3ef9f88203cd38e655f4ec1d22',
        ];
        yield 'a pair' => [
            '2023-12-30', DatePrecision::Day, 4157, PuzzlingType::Duo, 1,
            $head . "2023-12-30\n4157\nduo\n1",
            '1:2391c5e587f56ac4db7af85a87b7304088f26146d92e678809b01fe309271427',
        ];
        yield 'a team' => [
            '2023-12-30', DatePrecision::Day, 4157, PuzzlingType::Team, 1,
            $head . "2023-12-30\n4157\nteam\n1",
            '1:0ced5b3c1d8f0967b9fb6506c78a9cd45a3836d3dfeb3932b14caf41717a59dd',
        ];
        yield 'the second of two equal rows of one sheet' => [
            '2023-12-30', DatePrecision::Day, 4157, PuzzlingType::Solo, 2,
            $head . "2023-12-30\n4157\nsolo\n2",
            '1:f5d8e1890d11dc8f908840b973d07506c7c85dc01766d860525f98b58bc1aba8',
        ];
        yield 'neither a date nor a time' => [
            null, DatePrecision::Day, null, PuzzlingType::Solo, 1,
            $head . "no date\nno time\nsolo\n1",
            '1:4883a7e28a2fa4bf2f6e7049b440001ca035f934f39e6a93b48e8ee0c9a30a5c',
        ];
    }

    public function testTheKeysOfAnOwnedPuzzleAndOfAWishArePinned(): void
    {
        self::assertSame("source-key:1\nowned\n" . self::COZY_CABIN . "\nmain", ImportSourceKey::canonicalOwned(self::COZY_CABIN));
        self::assertSame('1:3c50b61e09a318509efa4ea5d296fa1131334d282ac3462b546616031a523f65', ImportSourceKey::owned(self::COZY_CABIN));

        self::assertSame("source-key:1\nowned\n" . self::COZY_CABIN . "\nlabel:to do", ImportSourceKey::canonicalOwned(self::COZY_CABIN, 'To Do'));
        self::assertSame('1:ef3ca92568fa0aaa9a8d0e83f384ef721ebf437239f2eaf084e344cfe0b60f73', ImportSourceKey::owned(self::COZY_CABIN, 'To Do'));
        self::assertSame(ImportSourceKey::owned(self::COZY_CABIN, 'To Do'), ImportSourceKey::owned(self::COZY_CABIN, '  to   DO '), 'The label is folded');

        self::assertSame(ImportSourceKey::owned(self::COZY_CABIN), ImportSourceKey::owned(self::COZY_CABIN, ''), 'No label = the main collection');
        self::assertSame(ImportSourceKey::owned(self::COZY_CABIN), ImportSourceKey::owned(self::COZY_CABIN, '   '));
        self::assertNotSame(ImportSourceKey::owned(self::COZY_CABIN), ImportSourceKey::owned(self::COZY_CABIN, 'main'), 'A collection called "main" is not the main collection');

        self::assertSame("source-key:1\nwish-list\n" . self::COZY_CABIN, ImportSourceKey::canonicalWishList(self::COZY_CABIN));
        self::assertSame('1:b2083b7137fa07889e6cf17a9fd173ba2d0c9e6bb03fbdbed7baeadb1322579f', ImportSourceKey::wishList(self::COZY_CABIN));
    }

    public function testARowThatIsAResultAndAnOwnedPuzzleHasTwoKeysAndNeitherKnowsTheOther(): void
    {
        $puzzle = ImportPuzzleKey::of('Cozy Cabin', 1000, 'Ravensburger');
        self::assertNotNull($puzzle);

        // One row: solved on 30 December 2023 in 1:09:17, and it is in the collection
        $result = ImportSourceKey::result($puzzle, self::day('2023-12-30'), DatePrecision::Day, 4157, PuzzlingType::Solo);
        $owned = ImportSourceKey::owned($puzzle);
        $wish = ImportSourceKey::wishList($puzzle);

        self::assertCount(3, array_unique([$result, $owned, $wish]));

        // The same puzzle on a to-do sheet (owned, no result) is the same owned puzzle
        self::assertSame($owned, ImportSourceKey::owned($puzzle));
        // … and the same result on a sheet that says nothing about owning is the same result
        self::assertSame($result, ImportSourceKey::result($puzzle, self::day('2023-12-30'), DatePrecision::Day, 4157, PuzzlingType::Solo));
    }

    public function testAKeyIsExactEqualityOnly(): void
    {
        $key = static fn (string $day, DatePrecision $precision): string => ImportSourceKey::result(
            self::COZY_CABIN,
            self::day($day),
            $precision,
            4157,
            PuzzlingType::Solo,
        );

        // Only the part the precision names is used
        self::assertSame($key('2023-12-01', DatePrecision::Month), $key('2023-12-30', DatePrecision::Month));
        self::assertSame($key('2023-01-01', DatePrecision::Year), $key('2023-12-30', DatePrecision::Year));

        // A month and a day of that month are unrelated keys - pairing them is not a key's business
        self::assertNotSame($key('2023-12-01', DatePrecision::Day), $key('2023-12-01', DatePrecision::Month));
        self::assertNotSame($key('2023-12-30', DatePrecision::Month), $key('2023-12-30', DatePrecision::Year));
        self::assertNotSame($key('2023-12-29', DatePrecision::Day), $key('2023-12-30', DatePrecision::Day));
    }

    public function testTheCalendarDayCountsNotTheMoment(): void
    {
        $evening = new DateTimeImmutable('2023-12-30 23:30:00', new DateTimeZone('Pacific/Auckland'));

        self::assertSame(
            ImportSourceKey::result(self::COZY_CABIN, self::day('2023-12-30'), DatePrecision::Day, 4157, PuzzlingType::Solo),
            ImportSourceKey::result(self::COZY_CABIN, $evening, DatePrecision::Day, 4157, PuzzlingType::Solo),
        );
    }

    public function testATimeOfZeroIsNoTimeAndAnOccurrenceBelowOneIsTheFirst(): void
    {
        $key = static fn (null|int $seconds, int $occurrence): string => ImportSourceKey::result(
            self::COZY_CABIN,
            self::day('2023-12-30'),
            DatePrecision::Day,
            $seconds,
            PuzzlingType::Solo,
            $occurrence,
        );

        self::assertSame($key(null, 1), $key(0, 1));
        self::assertSame($key(null, 1), $key(-30, 1));
        self::assertSame($key(4157, 1), $key(4157, 0));
        self::assertNotSame($key(4157, 1), $key(4158, 1));
        self::assertNotSame($key(4157, 1), $key(4157, 2));
    }

    public function testThePuzzleKeyIsComparedTrimmedAndInLowerCase(): void
    {
        self::assertSame(
            ImportSourceKey::owned(self::COZY_CABIN),
            ImportSourceKey::owned(' ' . strtoupper(self::COZY_CABIN) . ' '),
        );
    }

    public function testADateAtItsPrecision(): void
    {
        $date = self::day('2023-03-05');

        self::assertSame('2023-03-05', ImportSourceKey::dateAt($date, DatePrecision::Day));
        self::assertSame('2023-03', ImportSourceKey::dateAt($date, DatePrecision::Month));
        self::assertSame('2023', ImportSourceKey::dateAt($date, DatePrecision::Year));
    }

    private static function day(string $day): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
        self::assertNotFalse($date);

        return $date;
    }
}
