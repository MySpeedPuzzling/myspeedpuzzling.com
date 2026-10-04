<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;

final class PuzzleNamesTest extends TestCase
{
    public function testArrayRoundTrip(): void
    {
        $rows = [
            ['name' => 'Kruh barev: Mušle', 'language' => 'cs'],
            ['name' => 'Seashells', 'language' => null],
        ];

        $names = PuzzleNames::fromArray($rows);

        self::assertSame($rows, $names->toArray());
        self::assertCount(2, $names);
        self::assertFalse($names->isEmpty());
        self::assertEquals(new PuzzleName('Kruh barev: Mušle', 'cs'), $names->all()[0]);
    }

    #[DataProvider('jsonValues')]
    public function testFromJson(null|string $json, mixed $expected): void
    {
        self::assertSame($expected, PuzzleNames::fromJson($json)->toArray());
    }

    /**
     * @return iterable<string, array{null|string, list<array{name: string, language: null|string}>}>
     */
    public static function jsonValues(): iterable
    {
        yield 'null' => [null, []];
        yield 'empty string' => ['', []];
        yield 'empty list' => ['[]', []];
        yield 'invalid JSON' => ['[{"name": ', []];
        yield 'not a list' => ['"Mušle"', []];
        yield 'an object' => ['{"name": "Mušle"}', []];
        yield 'a name with its language' => ['[{"name": "Mušle", "language": "cs"}]', [['name' => 'Mušle', 'language' => 'cs']]];
        yield 'language missing' => ['[{"name": "Mušle"}]', [['name' => 'Mušle', 'language' => null]]];
        yield 'empty language' => ['[{"name": "Mušle", "language": ""}]', [['name' => 'Mušle', 'language' => null]]];
        yield 'unknown keys ignored' => ['[{"language": "de", "edition": 2, "name": "Muscheln"}]', [['name' => 'Muscheln', 'language' => 'de']]];
        yield 'entries without a name skipped' => ['[{"language": "de"}, "loose", {"name": 12}, {"name": "Muscheln"}]', [['name' => 'Muscheln', 'language' => null]]];
    }

    #[DataProvider('shownForCases')]
    public function testShownFor(string $language, null|string $expected): void
    {
        $names = PuzzleNames::fromArray([
            ['name' => 'Untagged', 'language' => null],
            ['name' => 'Mušle', 'language' => 'cs'],
            ['name' => 'Conchas', 'language' => 'pt'],
            ['name' => 'Muscheln', 'language' => 'de'],
            ['name' => 'Second Czech', 'language' => 'cs'],
            ['name' => 'Conchas do Brasil', 'language' => 'pt-BR'],
        ]);

        self::assertSame($expected, $names->shownFor($language)?->name);
    }

    /**
     * @return iterable<string, array{string, null|string}>
     */
    public static function shownForCases(): iterable
    {
        yield 'the first one of the language' => ['cs', 'Mušle'];
        yield 'a region matches its base language' => ['pt-BR', 'Conchas'];
        yield 'a base language matches a regional name' => ['pt', 'Conchas'];
        yield 'German' => ['de', 'Muscheln'];
        yield 'never a name without language' => ['en', null];
        yield 'empty language' => ['', null];
    }

    public function testShownForMatchesARegionalNameByItsBase(): void
    {
        $names = PuzzleNames::fromArray([['name' => 'Conchas do Brasil', 'language' => 'pt-BR']]);

        self::assertSame('Conchas do Brasil', $names->shownFor('pt')?->name);
        self::assertSame('Conchas do Brasil', $names->shownFor('pt-PT')?->name);
    }

    public function testLegacyAlternativeNameIsTheFirstCzechName(): void
    {
        $names = PuzzleNames::fromArray([
            ['name' => 'Muscheln', 'language' => 'de'],
            ['name' => 'Mušle', 'language' => 'cs'],
            ['name' => 'Lastury', 'language' => 'cs'],
        ]);

        self::assertSame('Mušle', $names->legacyAlternativeName());
    }

    public function testLegacyAlternativeNameIsTheFirstNameWithoutACzechOne(): void
    {
        self::assertSame('Muscheln', PuzzleNames::fromArray([
            ['name' => 'Muscheln', 'language' => 'de'],
            ['name' => 'Seashells', 'language' => null],
        ])->legacyAlternativeName());

        self::assertSame('Mušle CZ', PuzzleNames::fromArray([['name' => 'Mušle CZ', 'language' => 'cs-CZ']])->legacyAlternativeName());
        self::assertNull((new PuzzleNames())->legacyAlternativeName());
    }

    /**
     * @param list<array{name: string, language: null|string}> $rows
     * @param list<array{name: string, language: null|string}> $expected
     */
    #[DataProvider('legacyFieldValues')]
    public function testWithLegacyAlternativeName(array $rows, null|string $value, array $expected): void
    {
        self::assertSame($expected, PuzzleNames::fromArray($rows)->withLegacyAlternativeName($value)->toArray());
    }

    /**
     * @return iterable<string, array{list<array{name: string, language: null|string}>, null|string, list<array{name: string, language: null|string}>}>
     */
    public static function legacyFieldValues(): iterable
    {
        $names = [
            ['name' => 'Muscheln', 'language' => 'de'],
            ['name' => 'Mušle', 'language' => 'cs'],
            ['name' => 'Seashells', 'language' => null],
        ];

        yield 'the same value: unchanged' => [$names, 'Mušle', $names];
        yield 'the same value with spaces around: unchanged' => [$names, '  Mušle ', $names];
        yield 'blank: the Czech name removed' => [$names, '  ', [$names[0], $names[2]]];
        yield 'null: the Czech name removed' => [$names, null, [$names[0], $names[2]]];
        yield 'another value: the Czech name renamed, still Czech' => [
            $names,
            'Lastury',
            [$names[0], ['name' => 'Lastury', 'language' => 'cs'], $names[2]],
        ];
        yield 'without a Czech name the first one is edited, its language kept' => [
            [$names[0], $names[2]],
            'Meeresmuscheln',
            [['name' => 'Meeresmuscheln', 'language' => 'de'], $names[2]],
        ];
        yield 'empty list, Czech letters: a Czech name' => [[], 'Kouzelná zahrada s řekou', [['name' => 'Kouzelná zahrada s řekou', 'language' => 'cs']]];
        yield 'empty list, accents Czech shares with others: no language' => [[], 'Café à Paris', [['name' => 'Café à Paris', 'language' => null]]];
        yield 'empty list, blank: still empty' => [[], '', []];
        yield 'empty list, null: still empty' => [[], null, []];
        yield 'whitespace inside is cleaned' => [[], "Sea \t shells", [['name' => 'Sea shells', 'language' => null]]];
    }

    public function testUnionKeepsThisListFirstAndAddsTheOthersNames(): void
    {
        $survivor = PuzzleNames::fromArray([['name' => 'Mušle', 'language' => 'cs']]);
        $other = PuzzleNames::fromArray([
            ['name' => 'Muscheln', 'language' => 'de'],
            ['name' => 'Mušle', 'language' => 'cs'],
        ]);

        self::assertSame([
            ['name' => 'Mušle', 'language' => 'cs'],
            ['name' => 'Muscheln', 'language' => 'de'],
        ], $survivor->union($other)->toArray());
    }

    /**
     * @param list<array{name: string, language: null|string}> $first
     * @param list<array{name: string, language: null|string}> $second
     * @param list<array{name: string, language: null|string}> $expected
     */
    #[DataProvider('unionKeepRules')]
    public function testUnionKeepsOneOfTwoNamesFoldingEqual(array $first, array $second, array $expected): void
    {
        self::assertSame($expected, PuzzleNames::fromArray($first)->union(PuzzleNames::fromArray($second))->toArray());
    }

    /**
     * @return iterable<string, array{list<array{name: string, language: null|string}>, list<array{name: string, language: null|string}>, list<array{name: string, language: null|string}>}>
     */
    public static function unionKeepRules(): iterable
    {
        yield 'the tagged one over an untagged one, at the earlier position' => [
            [['name' => 'Kouzelna zahrada', 'language' => null], ['name' => 'Other', 'language' => null]],
            [['name' => 'Kouzelna zahrada', 'language' => 'cs']],
            [['name' => 'Kouzelna zahrada', 'language' => 'cs'], ['name' => 'Other', 'language' => null]],
        ];
        yield 'the tagged one even when the untagged one is accented' => [
            [['name' => 'Kouzelná zahrada', 'language' => null]],
            [['name' => 'KOUZELNA ZAHRADA', 'language' => 'cs']],
            [['name' => 'KOUZELNA ZAHRADA', 'language' => 'cs']],
        ];
        yield 'the accented one when both are untagged' => [
            [['name' => 'Kouzelna zahrada', 'language' => null]],
            [['name' => 'Kouzelná zahrada', 'language' => null]],
            [['name' => 'Kouzelná zahrada', 'language' => null]],
        ];
        yield 'the accented one when both are tagged' => [
            [['name' => 'Kouzelna zahrada', 'language' => 'cs']],
            [['name' => 'Kouzelná zahrada', 'language' => 'sk']],
            [['name' => 'Kouzelná zahrada', 'language' => 'sk']],
        ];
        yield 'the first one when nothing tells them apart' => [
            [['name' => 'Seashells', 'language' => null]],
            [['name' => 'SEASHELLS', 'language' => null]],
            [['name' => 'Seashells', 'language' => null]],
        ];
        yield 'names that differ only by an apostrophe are one name' => [
            [['name' => "Peggy's Riverside", 'language' => null], ['name' => 'Where´s Wally?', 'language' => null]],
            [['name' => 'Peggy’s Riverside', 'language' => 'en'], ['name' => 'Wheres Wally?', 'language' => null]],
            [['name' => 'Peggy’s Riverside', 'language' => 'en'], ['name' => 'Where´s Wally?', 'language' => null]],
        ];
        yield 'numeric names stay apart and in order' => [
            [['name' => '1000', 'language' => null], ['name' => '12', 'language' => null]],
            [['name' => '1000', 'language' => 'cs']],
            [['name' => '1000', 'language' => 'cs'], ['name' => '12', 'language' => null]],
        ];
    }

    public function testCleanedForDropsWhatFoldsEqualToTheMainTitleAndCleansTheRest(): void
    {
        $names = PuzzleNames::fromArray([
            ['name' => 'SEASHELLS', 'language' => null],
            ['name' => "  Kruh  barev:\tMušle ", 'language' => 'CS'],
            ['name' => "\u{200B}", 'language' => null],
            ['name' => 'Muscheln', 'language' => 'xx'],
            ['name' => 'Kruh barev: Musle', 'language' => null],
            ['name' => 'Conchas', 'language' => 'pt_br'],
        ]);

        self::assertSame([
            ['name' => 'Kruh barev: Mušle', 'language' => 'cs'],
            ['name' => 'Muscheln', 'language' => null],
            ['name' => 'Conchas', 'language' => 'pt-BR'],
        ], $names->cleanedFor('Seashells')->toArray());

        // The main title with another apostrophe is the main title
        self::assertSame([], PuzzleNames::fromArray([['name' => 'Where’s Wally?', 'language' => 'cs']])->cleanedFor("Where's Wally?")->toArray());
    }

    public function testCleanName(): void
    {
        self::assertSame('Sea shells of Bali', PuzzleNames::cleanName("  Sea\u{00A0}shells \t of\n\nBali\u{200B} "));
        self::assertSame('', PuzzleNames::cleanName(" \u{FEFF} "));
    }

    public function testDiffOfEqualListsIsEmpty(): void
    {
        $names = PuzzleNames::fromArray([['name' => 'Mušle', 'language' => 'cs']]);

        self::assertTrue($names->diff($names)->isEmpty());
    }

    public function testDiffFindsAddedRemovedAndChangedNames(): void
    {
        $before = PuzzleNames::fromArray([
            ['name' => 'Musle', 'language' => null],
            ['name' => 'Muscheln', 'language' => 'de'],
            ['name' => 'Conchas', 'language' => 'es'],
            ['name' => 'Seashells', 'language' => null],
        ]);
        $after = PuzzleNames::fromArray([
            ['name' => 'Mušle', 'language' => 'cs'],
            ['name' => 'Meeresmuscheln', 'language' => 'de'],
            ['name' => 'Seashells', 'language' => null],
            ['name' => '貝殻', 'language' => 'ja'],
        ]);

        $diff = $after->diff($before);

        self::assertEquals([new PuzzleName('貝殻', 'ja')], $diff->added);
        self::assertEquals([new PuzzleName('Conchas', 'es')], $diff->removed);
        self::assertEquals([
            ['from' => new PuzzleName('Musle', null), 'to' => new PuzzleName('Mušle', 'cs')],
            ['from' => new PuzzleName('Muscheln', 'de'), 'to' => new PuzzleName('Meeresmuscheln', 'de')],
        ], $diff->changed);
        self::assertFalse($diff->isEmpty());
    }

    public function testDiffAppliedToTheListItWasMadeFromGivesTheNewList(): void
    {
        $before = PuzzleNames::fromArray([
            ['name' => 'Musle', 'language' => null],
            ['name' => 'Muscheln', 'language' => 'de'],
            ['name' => 'Conchas', 'language' => 'es'],
        ]);
        $after = PuzzleNames::fromArray([
            ['name' => 'Mušle', 'language' => 'cs'],
            ['name' => 'Meeresmuscheln', 'language' => 'de'],
            ['name' => '貝殻', 'language' => 'ja'],
        ]);

        self::assertSame($after->toArray(), $after->diff($before)->applyTo($before)->toArray());
    }

    public function testDiffAppliedToAListChangedMeanwhileKeepsTheOtherChanges(): void
    {
        $original = PuzzleNames::fromArray([
            ['name' => 'Musle', 'language' => null],
            ['name' => 'Muscheln', 'language' => 'de'],
            ['name' => 'Conchas', 'language' => 'es'],
        ]);
        $proposed = PuzzleNames::fromArray([
            ['name' => 'Mušle', 'language' => 'cs'],
            ['name' => 'Muscheln', 'language' => 'de'],
            ['name' => 'Coquillages', 'language' => 'fr'],
        ]);
        // Meanwhile a moderator added a Japanese name, renamed the German one and removed the Spanish one
        $current = PuzzleNames::fromArray([
            ['name' => 'Musle', 'language' => null],
            ['name' => 'Meeresmuscheln', 'language' => 'de'],
            ['name' => '貝殻', 'language' => 'ja'],
        ]);

        self::assertSame([
            ['name' => 'Mušle', 'language' => 'cs'],
            ['name' => 'Meeresmuscheln', 'language' => 'de'],
            ['name' => '貝殻', 'language' => 'ja'],
            ['name' => 'Coquillages', 'language' => 'fr'],
        ], $proposed->diff($original)->applyTo($current)->toArray());
    }

    public function testAChangeOfANameGoneMeanwhileAddsItsResult(): void
    {
        $original = PuzzleNames::fromArray([['name' => 'Musle', 'language' => null]]);
        $proposed = PuzzleNames::fromArray([['name' => 'Mušle', 'language' => 'cs']]);

        self::assertSame(
            [['name' => 'Muscheln', 'language' => 'de'], ['name' => 'Mušle', 'language' => 'cs']],
            $proposed->diff($original)->applyTo(PuzzleNames::fromArray([['name' => 'Muscheln', 'language' => 'de']]))->toArray(),
        );
    }

    public function testAnAddedNameTheListHoldsMeanwhileMergesWithIt(): void
    {
        $proposed = PuzzleNames::fromArray([['name' => 'Mušle', 'language' => 'cs']]);
        $current = PuzzleNames::fromArray([['name' => 'Musle', 'language' => null], ['name' => 'Other', 'language' => null]]);

        self::assertSame(
            [['name' => 'Mušle', 'language' => 'cs'], ['name' => 'Other', 'language' => null]],
            $proposed->diff(new PuzzleNames())->applyTo($current)->toArray(),
        );
    }

    public function testFormLimitsPass(): void
    {
        $names = new PuzzleNames(array_map(
            static fn (int $i): PuzzleName => new PuzzleName(str_repeat('a', 254) . $i % 10, null),
            range(1, PuzzleNames::FORM_MAX_NAMES),
        ));

        $names->assertFormLimits();

        $this->expectNotToPerformAssertions();
    }

    public function testMoreThanTwentyNamesAreRefusedFromAForm(): void
    {
        $names = new PuzzleNames(array_map(
            static fn (int $i): PuzzleName => new PuzzleName('Name ' . $i, null),
            range(1, PuzzleNames::FORM_MAX_NAMES + 1),
        ));

        $this->expectException(InvalidPuzzleValues::class);
        $names->assertFormLimits();
    }

    public function testANameLongerThan255CharactersIsRefusedFromAForm(): void
    {
        $names = new PuzzleNames([new PuzzleName(str_repeat('ř', 256), 'cs')]);

        $this->expectException(InvalidPuzzleValues::class);
        $names->assertFormLimits();
    }
}
