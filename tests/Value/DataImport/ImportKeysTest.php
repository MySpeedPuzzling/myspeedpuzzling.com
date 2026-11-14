<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value\DataImport;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\DataImport\DatePrecision;
use SpeedPuzzling\Web\Value\DataImport\ImportBrandText;
use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleKey;
use SpeedPuzzling\Web\Value\DataImport\ImportRowKind;
use SpeedPuzzling\Web\Value\DataImport\ImportSourceKey;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * The keys of the import are stored for ever and compared for ever (docs/features/data-import/README.md "Keys,
 * exactly", "Never twice"). The exact outputs are pinned: a test that turns red here means every stored key would
 * stop matching - change the canonical text only together with a migration of the stored keys.
 */
final class ImportKeysTest extends TestCase
{
    private const string COZY_CABIN = '2337190dd246b865254b211eb453cb59b8f573757b46e11ad7e2efe13a125deb';

    #[DataProvider('pinnedPuzzleKeys')]
    public function testThePuzzleKeyIsPinned(string $name, null|int $pieces, null|string $brandId, null|string $brandText, string $canonical, string $key): void
    {
        self::assertSame($canonical, ImportPuzzleKey::canonical($name, $pieces, $brandId, $brandText));
        self::assertSame($key, ImportPuzzleKey::of($name, $pieces, $brandId, $brandText));
        self::assertSame($key, hash('sha256', $canonical), 'The key is the sha256 of the canonical text');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
    }

    /**
     * @return iterable<string, array{string, null|int, null|string, null|string, string, string}>
     */
    public static function pinnedPuzzleKeys(): iterable
    {
        yield 'name, pieces and a brand as text' => [
            'Cozy Cabin', 1000, null, 'Ravensburger',
            "puzzle-key:1\ncozy cabin\n1000\ntext:ravensburger",
            self::COZY_CABIN,
        ];
        yield 'a brand resolved to a catalogue brand: its id, whatever the text' => [
            'Cozy Cabin', 1000, '018e4c1f-5a5c-7a3c-9d2e-1f2a3b4c5d6e', 'Ravensburger',
            "puzzle-key:1\ncozy cabin\n1000\nid:018e4c1f-5a5c-7a3c-9d2e-1f2a3b4c5d6e",
            '6acd42c39e9c87d6d9f33d55d1426711eb774546a1b3031147e63d0d3bfb734b',
        ];
        yield 'a part in brackets stays; a brand of two words is its letters' => [
            'Cozy Cabin (2nd edition)', 1000, null, 'Master Pieces',
            "puzzle-key:1\ncozy cabin (2nd edition)\n1000\ntext:masterpieces",
            '7bd01dc255b911b22f04f567b2ebac592b05751542c5b264919d61a7ae9ee9b9',
        ];
        yield 'another piece count is another puzzle' => [
            'Cozy Cabin', 500, null, 'Ravensburger',
            "puzzle-key:1\ncozy cabin\n500\ntext:ravensburger",
            'b3dfedc283ff99da72df89b45631c73f7fbe41ae9e6de380db2eac11af90b358',
        ];
        yield 'no pieces and no brand' => [
            'Cozy Cabin', null, null, null,
            "puzzle-key:1\ncozy cabin\n\ntext:",
            '615cabb308b85cc23e4a8f3a790ed5d0f20bdee57666e0524a3a3cbad933ced0',
        ];
        yield 'an apostrophe goes, a question mark stays (SearchText::fold)' => [
            'Where\'s Wally?', 1000, null, 'Jumbo',
            "puzzle-key:1\nwheres wally?\n1000\ntext:jumbo",
            'bd6062849e3f4fa1e204fe5183c48e5041980fc567b9447ea8bf129416c58028',
        ];
        yield 'Latin letters without their accents' => [
            'Łódź nocą', 2000, null, 'Trefl',
            "puzzle-key:1\nlodz noca\n2000\ntext:trefl",
            'e54f8f42555c283fcc49f5a8ba3adf78849b7f25346c1f3d4b962ba7e5297237',
        ];
        yield 'ß is ss' => [
            'Straße im Regen', 1500, null, 'Schmidt Spiele',
            "puzzle-key:1\nstrasse im regen\n1500\ntext:schmidtspiele",
            '34145fe2704c82a0a9c41cac3ea8acd2efcb293484799c434eb91b1061e322dc',
        ];
        yield 'Japanese stays as it is' => [
            '富士山', 300, null, 'エポック社',
            "puzzle-key:1\n富士山\n300\ntext:エポック社",
            'a3b4ab5bf513068ce74df09ca85059eb27c2055c8cb514feef5b1c8069863674',
        ];
        yield 'a colon in the name, no brand' => [
            'Circle of Colours: Ocean', 500, null, null,
            "puzzle-key:1\ncircle of colours: ocean\n500\ntext:",
            'dabe91108adc4185d4af5d9ebc662da11657ebbc3af298ee542fea48118cead5',
        ];
        yield 'no name' => [
            '', 1000, null, 'Ravensburger',
            "puzzle-key:1\n\n1000\ntext:ravensburger",
            'bcc5ec3a80b00b8371e0c85e8a06c7e9fbb7a72235140cb4563ff56826ce005f',
        ];
        yield 'a trade mark sign in the brand' => [
            'Cozy Cabin', 1000, null, 'Clementoni®',
            "puzzle-key:1\ncozy cabin\n1000\ntext:clementoni",
            'c60449b8a3b5b84975f289d7058a05110eb1c36b5a5c0150ea2a4d9271258cfc',
        ];
    }

    #[DataProvider('samePuzzle')]
    public function testWhatIsTheSamePuzzle(string $name, null|int $pieces, null|string $brandId, null|string $brandText): void
    {
        self::assertSame(self::COZY_CABIN, ImportPuzzleKey::of($name, $pieces, $brandId, $brandText));
    }

    /**
     * @return iterable<string, array{string, null|int, null|string, null|string}>
     */
    public static function samePuzzle(): iterable
    {
        yield 'upper case' => ['COZY CABIN', 1000, null, 'RAVENSBURGER'];
        yield 'spaces around and inside' => ['  Cozy   Cabin ', 1000, null, ' Ravensburger '];
        yield 'a non-breaking space' => ["Cozy\u{00A0}Cabin", 1000, null, 'Ravensburger'];
        yield 'a line break in the cell' => ["Cozy\nCabin", 1000, null, 'Ravensburger'];
        yield 'full-width letters' => ['Ｃｏｚｙ Ｃａｂｉｎ', 1000, null, 'Ravensburger'];
        yield 'accents nobody meant' => ['Cózy Cabín', 1000, null, 'Ravensburger'];
        yield 'a direction mark' => ["\u{200E}Cozy Cabin", 1000, null, 'Ravensburger'];
        yield 'the brand with a dash' => ['Cozy Cabin', 1000, null, 'Ravens-burger'];
        yield 'the brand in two words' => ['Cozy Cabin', 1000, null, 'Ravens Burger'];
        yield 'the brand with a sign' => ['Cozy Cabin', 1000, null, 'Ravensburger®'];
        yield 'an empty brand id is no id' => ['Cozy Cabin', 1000, '', 'Ravensburger'];
        yield 'a blank brand id is no id' => ['Cozy Cabin', 1000, '  ', 'Ravensburger'];
    }

    #[DataProvider('anotherPuzzle')]
    public function testWhatIsAnotherPuzzle(string $name, null|int $pieces, null|string $brandId, null|string $brandText): void
    {
        self::assertNotSame(self::COZY_CABIN, ImportPuzzleKey::of($name, $pieces, $brandId, $brandText));
    }

    /**
     * @return iterable<string, array{string, null|int, null|string, null|string}>
     */
    public static function anotherPuzzle(): iterable
    {
        yield 'another name' => ['Cosy Cabin', 1000, null, 'Ravensburger'];
        yield 'a part in brackets' => ['Cozy Cabin (XXL)', 1000, null, 'Ravensburger'];
        yield 'another piece count' => ['Cozy Cabin', 1001, null, 'Ravensburger'];
        yield 'no piece count' => ['Cozy Cabin', null, null, 'Ravensburger'];
        yield 'another brand' => ['Cozy Cabin', 1000, null, 'Schmidt'];
        yield 'no brand' => ['Cozy Cabin', 1000, null, null];
        yield 'an empty brand' => ['Cozy Cabin', 1000, null, ''];
        yield 'the brand resolved to a catalogue brand' => ['Cozy Cabin', 1000, '018e4c1f-5a5c-7a3c-9d2e-1f2a3b4c5d6e', 'Ravensburger'];
        yield 'punctuation in the name counts' => ['Cozy-Cabin', 1000, null, 'Ravensburger'];
    }

    public function testAResolvedBrandMakesTheSpellingIrrelevant(): void
    {
        $id = '018e4c1f-5a5c-7a3c-9d2e-1f2a3b4c5d6e';

        self::assertSame(
            ImportPuzzleKey::of('Cozy Cabin', 1000, $id, 'Ravensburger'),
            ImportPuzzleKey::of('Cozy Cabin', 1000, $id, 'Ravensburger Spieleverlag GmbH'),
        );
        self::assertSame(
            ImportPuzzleKey::of('Cozy Cabin', 1000, $id, null),
            ImportPuzzleKey::of('Cozy Cabin', 1000, strtoupper($id), 'RB'),
            'An id is compared in lower case',
        );
    }

    public function testNoPartCanRunIntoTheNext(): void
    {
        // A name that ends like a piece count, a brand that starts like one: the lines keep them apart
        self::assertNotSame(
            ImportPuzzleKey::of('Cabin 1000', null, null, 'x'),
            ImportPuzzleKey::of('Cabin', 1000, null, 'x'),
        );
        self::assertNotSame(
            ImportPuzzleKey::of("Cabin\n1000", null, null, 'x'),
            ImportPuzzleKey::of('Cabin', 1000, null, 'x'),
            'A line break in a name is a space once folded',
        );
        self::assertNotSame(
            ImportPuzzleKey::of('Cabin', 1000, null, 'id:018e4c1f-5a5c-7a3c-9d2e-1f2a3b4c5d6e'),
            ImportPuzzleKey::of('Cabin', 1000, '018e4c1f-5a5c-7a3c-9d2e-1f2a3b4c5d6e', null),
            'A brand text cannot pose as an id',
        );
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

    #[DataProvider('pinnedSourceKeys')]
    public function testTheSourceKeyIsPinned(
        ImportRowKind $kind,
        null|string $day,
        DatePrecision $precision,
        null|int $seconds,
        null|PuzzlingType $type,
        int $occurrence,
        string $canonical,
        string $key,
    ): void {
        $date = $day === null ? null : self::day($day);

        self::assertSame($canonical, ImportSourceKey::canonical($kind, self::COZY_CABIN, $date, $precision, $seconds, $type, $occurrence));
        self::assertSame($key, ImportSourceKey::of($kind, self::COZY_CABIN, $date, $precision, $seconds, $type, $occurrence));
        self::assertSame($key, hash('sha256', $canonical), 'The key is the sha256 of the canonical text');
    }

    /**
     * @return iterable<string, array{ImportRowKind, null|string, DatePrecision, null|int, null|PuzzlingType, int, string, string}>
     */
    public static function pinnedSourceKeys(): iterable
    {
        $head = "source-key:1\nresult\n" . self::COZY_CABIN . "\n";

        yield 'a result with a day and a time' => [
            ImportRowKind::Result, '2023-12-30', DatePrecision::Day, 4157, PuzzlingType::Solo, 1,
            $head . "2023-12-30\n4157\nsolo\n1",
            'bde51cd007949a1606914ab12a7fe209a821eaa84e7519330e7ba871712b5141',
        ];
        yield 'only the month is known' => [
            ImportRowKind::Result, '2023-12-30', DatePrecision::Month, 4157, PuzzlingType::Solo, 1,
            $head . "2023-12\n4157\nsolo\n1",
            '263b20e896759f25018dc6af6a4ac0da15371fa3bdb27006830b3a47c3b68f15',
        ];
        yield 'only the year is known' => [
            ImportRowKind::Result, '2023-12-30', DatePrecision::Year, 4157, PuzzlingType::Solo, 1,
            $head . "2023\n4157\nsolo\n1",
            'd83c12fe437ed53cc076a57a277262991d43cdedd83efd8b1787db16076fa08c',
        ];
        yield 'no date' => [
            ImportRowKind::Result, null, DatePrecision::Day, 4157, PuzzlingType::Solo, 1,
            $head . "no date\n4157\nsolo\n1",
            'b8b4f8120d6c6c65d96b9c779aa88b9784962df9bd616f16a6cd4a9275f36e32',
        ];
        yield 'no time' => [
            ImportRowKind::Result, '2023-12-30', DatePrecision::Day, null, PuzzlingType::Solo, 1,
            $head . "2023-12-30\nno time\nsolo\n1",
            '1b5e778d9365f0a77aab9420c2728c8b6a4a3b03fb18354abbdbd129d1bcab6d',
        ];
        yield 'a pair' => [
            ImportRowKind::Result, '2023-12-30', DatePrecision::Day, 4157, PuzzlingType::Duo, 1,
            $head . "2023-12-30\n4157\nduo\n1",
            'a9a8ceeb052c668d25787168831ae4c4d24d680cbb6c99da28576ef5d8d84624',
        ];
        yield 'a team' => [
            ImportRowKind::Result, '2023-12-30', DatePrecision::Day, 4157, PuzzlingType::Team, 1,
            $head . "2023-12-30\n4157\nteam\n1",
            '97598f0a3fd5b15e1e74b3ea67511a3a7b33c5364a5e0dfcbb532b611d2cfb98',
        ];
        yield 'the second of two equal rows of one sheet' => [
            ImportRowKind::Result, '2023-12-30', DatePrecision::Day, 4157, PuzzlingType::Solo, 2,
            $head . "2023-12-30\n4157\nsolo\n2",
            'a8e66d492b8cff0b6e9ec341b06b448b5b712c0c76bd60b747198a4731d54870',
        ];
        yield 'a result of an owned puzzle' => [
            ImportRowKind::Both, '2023-12-30', DatePrecision::Day, 4157, PuzzlingType::Solo, 1,
            "source-key:1\nboth\n" . self::COZY_CABIN . "\n2023-12-30\n4157\nsolo\n1",
            '216c53f2e4aa16b01ec27e7650d9b3848f240edf5203fb596754576851a93ab1',
        ];
        yield 'an owned puzzle' => [
            ImportRowKind::Owned, null, DatePrecision::Day, null, null, 1,
            "source-key:1\nowned\n" . self::COZY_CABIN . "\nno date\nno time\nno type\n1",
            '15cada9983944b1c366ef8a158db29d2118e5178612a2a7b401f56665cdf7888',
        ];
        yield 'a puzzle on the wish list' => [
            ImportRowKind::WishList, null, DatePrecision::Day, null, null, 1,
            "source-key:1\nwish_list\n" . self::COZY_CABIN . "\nno date\nno time\nno type\n1",
            'a661645d4f5d943b611496bf9be48ee273cec7b986750fc9a3aa53622af0df86',
        ];
        yield 'a result without a date and a time' => [
            ImportRowKind::Result, null, DatePrecision::Day, null, PuzzlingType::Solo, 1,
            $head . "no date\nno time\nsolo\n1",
            'aac9bfd16d1ec576a75c515eee5ef7626f49f6397566032106ae8510b98fd308',
        ];
    }

    public function testTheDateCountsOnlyAsFarAsItIsKnown(): void
    {
        $key = static fn (string $day, DatePrecision $precision): string => ImportSourceKey::of(
            ImportRowKind::Result,
            self::COZY_CABIN,
            self::day($day),
            $precision,
            4157,
            PuzzlingType::Solo,
        );

        self::assertSame($key('2023-12-01', DatePrecision::Month), $key('2023-12-30', DatePrecision::Month));
        self::assertSame($key('2023-01-01', DatePrecision::Year), $key('2023-12-30', DatePrecision::Year));
        self::assertNotSame($key('2023-12-01', DatePrecision::Day), $key('2023-12-01', DatePrecision::Month), 'A month is not its first day');
        self::assertNotSame($key('2023-12-29', DatePrecision::Day), $key('2023-12-30', DatePrecision::Day));
    }

    public function testTheCalendarDayCountsNotTheMoment(): void
    {
        $evening = new DateTimeImmutable('2023-12-30 23:30:00', new DateTimeZone('Pacific/Auckland'));

        self::assertSame(
            ImportSourceKey::of(ImportRowKind::Result, self::COZY_CABIN, self::day('2023-12-30'), DatePrecision::Day, 4157, PuzzlingType::Solo),
            ImportSourceKey::of(ImportRowKind::Result, self::COZY_CABIN, $evening, DatePrecision::Day, 4157, PuzzlingType::Solo),
        );
    }

    public function testATimeOfZeroIsNoTimeAndAnOccurrenceBelowOneIsTheFirst(): void
    {
        $key = static fn (null|int $seconds, int $occurrence): string => ImportSourceKey::of(
            ImportRowKind::Result,
            self::COZY_CABIN,
            self::day('2023-12-30'),
            DatePrecision::Day,
            $seconds,
            PuzzlingType::Solo,
            $occurrence,
        );

        self::assertSame($key(null, 1), $key(0, 1));
        self::assertSame($key(4157, 1), $key(4157, 0));
        self::assertNotSame($key(4157, 1), $key(4158, 1));
    }

    public function testThePuzzleKeyIsComparedInLowerCase(): void
    {
        self::assertSame(
            ImportSourceKey::of(ImportRowKind::Owned, self::COZY_CABIN, null, DatePrecision::Day, null, null),
            ImportSourceKey::of(ImportRowKind::Owned, strtoupper(self::COZY_CABIN), null, DatePrecision::Day, null, null),
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
