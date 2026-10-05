<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\PuzzleNameLanguageChoices;

final class PuzzleNameLanguageChoicesTest extends TestCase
{
    public function testEveryLanguageIsNamedInThePageLanguageAndSortedTheWayItSorts(): void
    {
        $choices = PuzzleNameLanguageChoices::choices('cs');

        self::assertCount(count(PuzzleNameLanguageChoices::LANGUAGES), $choices);
        self::assertSame('cs', $choices['Čeština']);
        self::assertSame('en', $choices['Angličtina']);

        // Czech sorts "ch" after "h"
        $labels = array_keys($choices);
        self::assertLessThan(array_search('Chorvatština', $labels, true), array_search('Hebrejština', $labels, true));
        self::assertLessThan(array_search('Dánština', $labels, true), array_search('Čeština', $labels, true));
    }

    public function testEnglishPagesNameTheLanguagesInEnglish(): void
    {
        $choices = PuzzleNameLanguageChoices::choices('en');

        self::assertSame('ja', $choices['Japanese']);
        self::assertSame('nb', $choices['Norwegian Bokmål']);
        self::assertSame('Arabic', array_key_first($choices));
    }

    public function testATagOutsideTheListStaysSelectable(): void
    {
        $choices = PuzzleNameLanguageChoices::choices('en', ['pt-BR', 'cs', null, 'not a tag', 'xx', 'PT-br']);

        self::assertSame('pt-BR', $choices['Portuguese (Brazil)']);
        self::assertCount(count(PuzzleNameLanguageChoices::LANGUAGES) + 1, $choices, 'Only valid tags in canonical casing are added, once');
    }

    public function testLabelsStartWithACapitalLetter(): void
    {
        self::assertSame('Čeština', PuzzleNameLanguageChoices::label('cs', 'cs'));
        self::assertSame('Portugalština (Brazílie)', PuzzleNameLanguageChoices::label('pt-BR', 'cs'));
        self::assertSame('Deutsch', PuzzleNameLanguageChoices::label('de', 'de'));
        self::assertSame('チェコ語', PuzzleNameLanguageChoices::label('cs', 'ja'));
    }
}
