<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value\DataImport;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\DataImport\ImportPersonText;
use SpeedPuzzling\Web\Value\DataImport\SolvingTypeWords;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * The one list of the words that say solo, pair or team (docs/features/data-import/people.md "A sheet called
 * Pairs") - read in a sheet's title in the six languages - and how a person of a file is read.
 */
final class SolvingTypeWordsTest extends TestCase
{
    #[DataProvider('provideTitles')]
    public function testWhatASheetsTitleSays(string $title, null|PuzzlingType $said): void
    {
        self::assertSame($said, SolvingTypeWords::ofTitle($title));
    }

    /**
     * @return iterable<string, array{string, null|PuzzlingType}>
     */
    public static function provideTitles(): iterable
    {
        $titles = [
            // English
            '500 piece Pairs' => PuzzlingType::Duo, 'Doubles 2023' => PuzzlingType::Duo, 'Team' => PuzzlingType::Team, 'TEAMS (4)' => PuzzlingType::Team,
            'Solo' => PuzzlingType::Solo, 'Individual results' => PuzzlingType::Solo, 'Family group' => PuzzlingType::Team,
            // Czech
            'Dvojice' => PuzzlingType::Duo, 'Páry 2024' => PuzzlingType::Duo, 'Týmy' => PuzzlingType::Team, 'Jednotlivci' => PuzzlingType::Solo, 'Sám' => PuzzlingType::Solo,
            // German
            'Paare' => PuzzlingType::Duo, 'Paar 500 Teile' => PuzzlingType::Duo, 'Gruppen' => PuzzlingType::Team, 'Einzel' => PuzzlingType::Solo,
            // Spanish
            'Parejas' => PuzzlingType::Duo, 'Equipos 2022' => PuzzlingType::Team, 'Individuales' => PuzzlingType::Solo,
            // French
            'Binômes' => PuzzlingType::Duo, 'En duo' => PuzzlingType::Duo, 'Équipes' => PuzzlingType::Team, 'Seule' => PuzzlingType::Solo,
            // Japanese
            'ペア500ピース' => PuzzlingType::Duo, 'チーム戦' => PuzzlingType::Team, 'ソロ' => PuzzlingType::Solo, '個人' => PuzzlingType::Solo,
            // Says nothing - or two things: nothing is taken from it
            'Results' => null, '2023' => null, 'Pairs and teams' => null, 'Repairs' => null, 'Teamwork' => null, 'Me and Ana' => null, 'Sheet 2' => null,
        ];

        foreach ($titles as $title => $said) {
            yield (string) $title => [(string) $title, $said];
        }
    }

    public function testACellSaysItWithTheSameWordsAndTheShortOnesOnlyACellCanMean(): void
    {
        self::assertSame(PuzzlingType::Duo, SolvingTypeWords::ofCell('pairs'));
        self::assertSame(PuzzlingType::Solo, SolvingTypeWords::ofCell('me'));
        self::assertSame(PuzzlingType::Duo, SolvingTypeWords::ofCell('2'));
        self::assertSame(PuzzlingType::Team, SolvingTypeWords::ofCell('equipe'));
        self::assertNull(SolvingTypeWords::ofCell('maybe'));
        // ... which a title never means
        self::assertNull(SolvingTypeWords::ofTitle('2'));
    }

    public function testARememberedKindIsNotCarriedOntoASheetWhoseTitleSaysAnother(): void
    {
        self::assertTrue(SolvingTypeWords::titleDisagrees('Pairs', PuzzlingType::Solo));
        self::assertFalse(SolvingTypeWords::titleDisagrees('Pairs', PuzzlingType::Duo));
        self::assertFalse(SolvingTypeWords::titleDisagrees('Results', PuzzlingType::Team));
        self::assertFalse(SolvingTypeWords::titleDisagrees('Pairs', null));
    }

    public function testAPersonOfAFileIsACodeAnIdAProfileAddressOrAName(): void
    {
        $code = ImportPersonText::of(' #AbC12 ');
        self::assertNotNull($code);
        self::assertSame(['#abc12', 'AbC12', 'AbC12', null, true], [$code->key, $code->name, $code->code, $code->playerId, $code->isWrittenAsPlayer()]);

        $address = ImportPersonText::of('https://myspeedpuzzling.com/en/player-profile/018D0000-0000-7000-8000-000000000001');
        self::assertNotNull($address);
        self::assertSame(['id:018d0000-0000-7000-8000-000000000001', '018d0000-0000-7000-8000-000000000001'], [$address->key, $address->playerId]);

        // A name is never a code: the same letters without the sign are a name, folded like a guest's
        $name = ImportPersonText::of('  Žofie   Nováková ');
        self::assertNotNull($name);
        self::assertSame(['g:zofie novakova', 'Žofie Nováková', null, null, false], [$name->key, $name->name, $name->code, $name->playerId, $name->isWrittenAsPlayer()]);
        self::assertSame('g:abc12', ImportPersonText::of('abc12')?->key);
        // Too long for a code: a name, without the sign
        self::assertSame(['g:not a code at all', 'not a code at all'], [ImportPersonText::of('#not a code at all')?->key, ImportPersonText::of('#not a code at all')?->name]);
        self::assertNull(ImportPersonText::of(' # '));
    }
}
