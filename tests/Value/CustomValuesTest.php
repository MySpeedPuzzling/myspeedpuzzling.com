<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\InvalidCustomValue;
use SpeedPuzzling\Web\Value\CustomFieldType;
use SpeedPuzzling\Web\Value\CustomValueProblem;
use SpeedPuzzling\Web\Value\CustomValues;

/**
 * The one parser of a value typed into a player's own field (docs/features/custom-fields.md).
 */
final class CustomValuesTest extends TestCase
{
    /**
     * @return iterable<string, array{CustomFieldType, list<string>, mixed, mixed}>
     */
    public static function provideAccepted(): iterable
    {
        yield 'nothing' => [CustomFieldType::Text, [], null, null];
        yield 'empty text' => [CustomFieldType::Text, [], "  \n ", null];
        yield 'text is trimmed' => [CustomFieldType::Text, [], '  corners first  ', 'corners first'];
        yield 'text keeps its lines' => [CustomFieldType::Text, [], "one\r\ntwo", "one\ntwo"];
        yield 'text of 500 characters' => [CustomFieldType::Text, [], str_repeat('ž', 500), str_repeat('ž', 500)];
        yield 'whole number' => [CustomFieldType::Number, [], '12', 12];
        yield 'negative number' => [CustomFieldType::Number, [], '-3', -3];
        yield 'decimal point' => [CustomFieldType::Number, [], '12.5', 12.5];
        yield 'decimal comma' => [CustomFieldType::Number, [], '12,5', 12.5];
        yield 'spaces between thousands' => [CustomFieldType::Number, [], '1 250', 1250];
        yield 'a fraction of zero is a whole number' => [CustomFieldType::Number, [], '7.0', 7];
        yield 'no-break spaces between thousands' => [CustomFieldType::Number, [], "1\u{00A0}250\u{202F}000", 1250000];
        yield 'thousands by spaces and a fraction' => [CustomFieldType::Number, [], '1 250,5', 1250.5];
        yield 'three decimals behind a zero can only be a fraction' => [CustomFieldType::Number, [], '0,125', 0.125];
        yield 'three decimals behind four digits can only be a fraction' => [CustomFieldType::Number, [], '1234.567', 1234.567];
        yield 'two decimals' => [CustomFieldType::Number, [], '1,25', 1.25];
        yield 'four decimals' => [CustomFieldType::Number, [], '1,2500', 1.25];
        yield 'text keeps its tabs' => [CustomFieldType::Text, [], "a\tb", "a\tb"];
        yield 'a number as a number' => [CustomFieldType::Number, [], 4.25, 4.25];
        yield 'date' => [CustomFieldType::Date, [], '2026-02-28', '2026-02-28'];
        yield 'yes' => [CustomFieldType::YesNo, [], 'yes', true];
        yield 'no' => [CustomFieldType::YesNo, [], 'no', false];
        yield 'no answer' => [CustomFieldType::YesNo, [], '', null];
        yield 'true' => [CustomFieldType::YesNo, [], true, true];
        yield 'one of the list' => [CustomFieldType::Choice, ['table', 'floor'], 'floor', 'floor'];
        yield 'nothing chosen' => [CustomFieldType::Choice, ['table', 'floor'], '', null];
        yield 'tags by commas' => [CustomFieldType::Tags, [], ' gradient , cats,,  Gradient ', ['gradient', 'cats']];
        yield 'tags as a list' => [CustomFieldType::Tags, [], ['night  sky', 'cats'], ['night sky', 'cats']];
        yield 'no tags' => [CustomFieldType::Tags, [], ' , ', null];
        yield 'a number as a tag' => [CustomFieldType::Tags, [], '2024, 500', ['2024', '500']];
    }

    /**
     * @param list<string> $choices
     */
    #[DataProvider('provideAccepted')]
    public function testAValueIsStoredInTheShapeOfItsType(CustomFieldType $type, array $choices, mixed $submitted, mixed $expected): void
    {
        self::assertSame($expected, CustomValues::normalise($type, $choices, $submitted));
    }

    /**
     * @return iterable<string, array{CustomFieldType, list<string>, mixed, CustomValueProblem}>
     */
    public static function provideRefused(): iterable
    {
        yield 'text of 501 characters' => [CustomFieldType::Text, [], str_repeat('a', 501), CustomValueProblem::TooLong];
        yield 'text as a list' => [CustomFieldType::Text, [], ['a'], CustomValueProblem::NotValid];
        yield 'words as a number' => [CustomFieldType::Number, [], 'twelve', CustomValueProblem::NotANumber];
        yield 'two decimal marks' => [CustomFieldType::Number, [], '1.2.3', CustomValueProblem::NotANumber];
        yield 'a formula as a number' => [CustomFieldType::Number, [], '=1+1', CustomValueProblem::NotANumber];
        yield 'an endless number' => [CustomFieldType::Number, [], '9999999999999999', CustomValueProblem::NotANumber];
        // One comma or point with exactly three digits behind 1-3: a thousand or a fraction? Never guessed
        yield 'a comma before three digits' => [CustomFieldType::Number, [], '1,250', CustomValueProblem::AmbiguousNumber];
        yield 'a point before three digits' => [CustomFieldType::Number, [], '1.250', CustomValueProblem::AmbiguousNumber];
        yield 'three digits before and behind' => [CustomFieldType::Number, [], '-125.500', CustomValueProblem::AmbiguousNumber];
        yield 'two separators' => [CustomFieldType::Number, [], '1,250.5', CustomValueProblem::NotANumber];
        yield 'a number that is no UTF-8' => [CustomFieldType::Number, [], "12\xC3\x28", CustomValueProblem::NotANumber];
        // What the database could not hold is a wrong value - never an exception later
        yield 'text with a NUL byte' => [CustomFieldType::Text, [], "edges\0last", CustomValueProblem::NotValid];
        yield 'text with an escape character' => [CustomFieldType::Text, [], "edges\x1Blast", CustomValueProblem::NotValid];
        yield 'text with a C1 control character' => [CustomFieldType::Text, [], "edges\u{0085}last", CustomValueProblem::NotValid];
        yield 'text that is no UTF-8' => [CustomFieldType::Text, [], "edges \xC3\x28 last", CustomValueProblem::NotValid];
        yield 'a tag with a NUL byte' => [CustomFieldType::Tags, [], "cats, sk\0y", CustomValueProblem::NotValid];
        yield 'a tag that is no UTF-8' => [CustomFieldType::Tags, [], ["cats", "\xFF\xFE"], CustomValueProblem::NotValid];
        yield 'a date with a NUL byte' => [CustomFieldType::Date, [], "2026-03\0-01", CustomValueProblem::NotADate];
        yield 'a choice that is no UTF-8' => [CustomFieldType::Choice, ['table'], "table\xC3\x28", CustomValueProblem::UnknownChoice];
        yield 'a day that does not exist' => [CustomFieldType::Date, [], '2026-02-30', CustomValueProblem::NotADate];
        yield 'another date format' => [CustomFieldType::Date, [], '28.02.2026', CustomValueProblem::NotADate];
        yield 'a year far away' => [CustomFieldType::Date, [], '0026-02-28', CustomValueProblem::NotADate];
        yield 'maybe' => [CustomFieldType::YesNo, [], 'maybe', CustomValueProblem::NotYesNo];
        yield 'not on the list' => [CustomFieldType::Choice, ['table', 'floor'], 'ceiling', CustomValueProblem::UnknownChoice];
        yield 'another letter case is another choice' => [CustomFieldType::Choice, ['table'], 'Table', CustomValueProblem::UnknownChoice];
        yield 'a tag of 51 characters' => [CustomFieldType::Tags, [], str_repeat('a', 51), CustomValueProblem::TagTooLong];
        yield '21 tags' => [CustomFieldType::Tags, [], implode(',', range(1, 21)), CustomValueProblem::TooManyTags];
        yield 'a tag that is no text' => [CustomFieldType::Tags, [], [['a']], CustomValueProblem::NotValid];
    }

    /**
     * @param list<string> $choices
     */
    #[DataProvider('provideRefused')]
    public function testAValueThatIsNotOfTheTypeIsRefusedWithItsReason(CustomFieldType $type, array $choices, mixed $submitted, CustomValueProblem $expected): void
    {
        try {
            CustomValues::normalise($type, $choices, $submitted);
            self::fail('The value was taken.');
        } catch (InvalidCustomValue $refused) {
            self::assertSame($expected, $refused->problem);
        }
    }

    public function testAStoredValueGoesBackIntoItsInputAndIsReadTheSameAgain(): void
    {
        foreach (
            [
                [CustomFieldType::Number, 12.5],
                [CustomFieldType::Number, 1250],
                [CustomFieldType::YesNo, true],
                [CustomFieldType::YesNo, false],
                [CustomFieldType::Tags, ['cats', 'night sky']],
                [CustomFieldType::Date, '2026-02-28'],
                [CustomFieldType::Text, "one\ntwo"],
            ] as [$type, $stored]
        ) {
            self::assertSame($stored, CustomValues::normalise($type, [], CustomValues::toInput($stored)));
        }

        self::assertSame('', CustomValues::toInput(null));
    }

    public function testTextTheDatabaseCanHoldIsValidUtf8WithoutControlCharacters(): void
    {
        self::assertTrue(CustomValues::isStorableText("Žluťoučký kůň\n\tパズル 🧩", multiline: true));
        self::assertTrue(CustomValues::isStorableText('', multiline: false));
        self::assertFalse(CustomValues::isStorableText("one\ntwo", multiline: false), 'A line break is a control character where the text has one line');
        self::assertFalse(CustomValues::isStorableText("a\0b", multiline: true));
        self::assertFalse(CustomValues::isStorableText("a\x7Fb", multiline: true));
        self::assertFalse(CustomValues::isStorableText("\xC3\x28", multiline: true));
    }

    public function testChangesAreMergedAndNullTakesAValueAway(): void
    {
        $values = new CustomValues(['a' => 'kept', 'b' => 2]);

        $changed = $values->with(['b' => null, 'c' => ['x']]);

        self::assertSame(['a' => 'kept', 'c' => ['x']], $changed->values);
        self::assertTrue($changed->with(['a' => null, 'c' => null])->isEmpty());
        self::assertNull($changed->of('b'));
    }

    public function testWhatTheColumnHoldsIsReadWithoutTrustingIt(): void
    {
        $values = CustomValues::fromStored('{"a":"text","b":3,"c":true,"d":["x",5,"y"],"e":{"k":"v"},"f":null}');

        self::assertSame(['a' => 'text', 'b' => 3, 'c' => true, 'd' => ['x', 'y'], 'e' => ['v']], $values->values);
        self::assertTrue(CustomValues::fromStored('not json')->isEmpty());
        self::assertTrue(CustomValues::fromStored(null)->isEmpty());
    }
}
