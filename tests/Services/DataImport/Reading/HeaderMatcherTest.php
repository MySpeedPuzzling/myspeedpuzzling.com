<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport\Reading;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\DataImport\Reading\HeaderMatcher;
use SpeedPuzzling\Web\Value\DataImport\FieldGuess;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\StandardCustomField;

/**
 * The headers of the real spreadsheets, of the other app's export, and the words of six languages
 * (docs/features/data-import/README.md "Recognising columns and values", docs/features/data-import/value-readers.md
 * "Headers").
 */
final class HeaderMatcherTest extends TestCase
{
    #[DataProvider('exactHeaders')]
    public function testAHeaderThatIsOneOfAFieldsWords(string $header, ImportField $field, null|StandardCustomField $readyMade = null): void
    {
        $guesses = (new HeaderMatcher())->guess($header);

        self::assertNotSame([], $guesses, $header);
        self::assertSame($field, $guesses[0]->field, $header);
        self::assertSame($readyMade, $guesses[0]->readyMadeField, $header);
        self::assertSame(FieldGuess::EXACT, $guesses[0]->score, $header);
        self::assertTrue($guesses[0]->isExact());
    }

    /**
     * @return iterable<string, array{0: string, 1: ImportField, 2?: StandardCustomField}>
     */
    public static function exactHeaders(): iterable
    {
        $readyMade = ImportField::ReadyMadeField;

        // The other app's export (Puzzle Tracker)
        yield 'app: Name' => ['Name', ImportField::PuzzleName];
        yield 'app: Brand' => ['Brand', ImportField::Brand];
        yield 'app: Number of elements' => ['Number of elements', ImportField::Pieces];
        yield 'app: Status' => ['Status', ImportField::Status];
        yield 'app: Time' => ['Time', ImportField::Time];
        yield 'app: Start date' => ['Start date', $readyMade, StandardCustomField::StartedOn];
        yield 'app: End date' => ['End date', ImportField::SolvedOn];
        yield 'app: Satisfaction' => ['Satisfaction', $readyMade, StandardCustomField::HowILikedIt];
        yield 'app: Difficulty' => ['Difficulty', $readyMade, StandardCustomField::HowHardItFelt];
        yield 'app: Quality' => ['Quality', $readyMade, StandardCustomField::Quality];
        yield 'app: Price' => ['Price', $readyMade, StandardCustomField::PricePaid];
        yield 'app: Missing pieces' => ['Missing pieces', $readyMade, StandardCustomField::MissingPieces];
        yield 'app: Artist' => ['Artist', $readyMade, StandardCustomField::Artist];
        yield 'app: Place of purchase' => ['Place of purchase', $readyMade, StandardCustomField::BoughtFrom];
        yield 'app: Puzzle type' => ['Puzzle type', $readyMade, StandardCustomField::Condition];
        yield 'app: Bar code' => ['Bar code', ImportField::Barcode];
        yield 'app: Tags' => ['Tags', $readyMade, StandardCustomField::MyTags];
        yield 'app: Notes' => ['Notes', $readyMade, StandardCustomField::PrivateNotes];

        // The three spreadsheets
        yield 'sheet: Completed' => ['Completed', ImportField::SolvedOn];
        yield 'sheet: Completed?' => ['Completed?', ImportField::SolvedOn];
        yield 'sheet: Title' => ['Title', ImportField::PuzzleName];
        yield 'sheet: Pieces' => ['Pieces', ImportField::Pieces];
        yield 'sheet: No. of Pieces' => ['No. of Pieces', ImportField::Pieces];
        yield 'sheet: puzzle name' => ['puzzle name', ImportField::PuzzleName];
        yield 'sheet: Piece Count' => ['Piece Count', ImportField::Pieces];
        yield 'sheet: Entered on MSP?' => ['Entered on MSP?', ImportField::AlreadyOnMySpeedPuzzling];
        yield 'sheet: No box?' => ['No box?', ImportField::SolvedWithoutBox];
        yield 'sheet: attempt' => ['attempt', ImportField::AttemptNumber];
        yield 'sheet: where from' => ['where from', $readyMade, StandardCustomField::BoughtFrom];
        yield 'sheet: purchase date' => ['purchase date', $readyMade, StandardCustomField::BoughtOn];
        yield 'sheet: days to complete' => ['days to complete', ImportField::Computed];
        yield 'sheet: PPM' => ['PPM', ImportField::Computed];
        yield 'sheet: Pieces per minute' => ['Pieces per minute', ImportField::Computed];
        yield 'sheet: In top percentage' => ['In top percentage', ImportField::Computed];
        yield 'sheet: Got rid of' => ['Got rid of', ImportField::NotOwned];
        yield 'sheet: Artist' => ['Artist', $readyMade, StandardCustomField::Artist];
        yield 'sheet: Condition' => ['Condition', $readyMade, StandardCustomField::Condition];
        yield 'sheet: Material' => ['Material', $readyMade, StandardCustomField::Material];

        // Computed-looking headers
        yield 'computed: ppm' => ['ppm', ImportField::Computed];
        yield 'computed: diff' => ['diff', ImportField::Computed];
        yield 'computed: percentage' => ['percentage', ImportField::Computed];
        yield 'computed: standing' => ['standing', ImportField::Computed];
        yield 'computed: place - before "where it is"' => ['place', ImportField::Computed];
        yield 'computed: %' => ['%', ImportField::Computed];
        yield 'computed: rank' => ['Rank', ImportField::Computed];
        yield 'computed: average' => ['Average', ImportField::Computed];
        yield 'computed: pieces/minute' => ['Pieces/minute', ImportField::Computed];

        // How headers are written
        yield 'upper case' => ['TITLE', ImportField::PuzzleName];
        yield 'spaces around' => ['  Title  ', ImportField::PuzzleName];
        yield 'a non-breaking space' => ["Piece\u{00A0}Count", ImportField::Pieces];
        yield 'a line break in the header' => ["Piece\nCount", ImportField::Pieces];
        yield 'an underscore' => ['piece_count', ImportField::Pieces];
        yield 'a dash' => ['Piece-Count', ImportField::Pieces];
        yield 'joined' => ['PieceCount', ImportField::Pieces];
        yield 'joined, lower' => ['puzzlename', ImportField::PuzzleName];
        yield 'a colon after' => ['Time:', ImportField::Time];
        yield 'a hash' => ['# pieces', ImportField::Pieces];
        yield 'a hash and of' => ['# of pieces', ImportField::Pieces];
        yield 'a hash after' => ['Attempt #', ImportField::AttemptNumber];
        yield 'a note in brackets' => ['Time (h:mm:ss)', ImportField::Time];
        yield 'a unit in square brackets' => ['Price [CZK]', $readyMade, StandardCustomField::PricePaid];
        yield 'a hint in brackets' => ['Entered on MSP (y/n)', ImportField::AlreadyOnMySpeedPuzzling];
        yield 'full-width letters' => ['Ｔｉｔｌｅ', ImportField::PuzzleName];
        yield 'an apostrophe' => ['Date d\'achat', $readyMade, StandardCustomField::BoughtOn];
        yield 'a typographic apostrophe' => ['Date d’achat', $readyMade, StandardCustomField::BoughtOn];
        yield 'without accents' => ['Nazev', ImportField::PuzzleName];

        // English
        yield 'en: manufacturer' => ['Manufacturer', ImportField::Brand];
        yield 'en: maker' => ['Maker', ImportField::Brand];
        yield 'en: puzzle' => ['Puzzle', ImportField::PuzzleName];
        yield 'en: pcs' => ['pcs', ImportField::Pieces];
        yield 'en: number of pieces' => ['Number of pieces', ImportField::Pieces];
        yield 'en: duration' => ['Duration', ImportField::Time];
        yield 'en: date' => ['Date', ImportField::SolvedOn];
        yield 'en: finished' => ['Finished', ImportField::SolvedOn];
        yield 'en: date completed' => ['Date completed', ImportField::SolvedOn];
        yield 'en: solved' => ['Solved', ImportField::SolvedOn];
        yield 'en: ean' => ['EAN', ImportField::Barcode];
        yield 'en: barcode' => ['Barcode', ImportField::Barcode];
        yield 'en: upc' => ['UPC', ImportField::Barcode];
        yield 'en: item number' => ['Item number', ImportField::BrandCode];
        yield 'en: sku' => ['SKU', ImportField::BrandCode];
        yield 'en: note' => ['Note', $readyMade, StandardCustomField::PrivateNotes];
        yield 'en: comment is private unless chosen' => ['Comment', $readyMade, StandardCustomField::PrivateNotes];
        yield 'en: public comment' => ['Public comment', ImportField::PublicComment];
        yield 'en: try' => ['Try', ImportField::AttemptNumber];
        yield 'en: first try' => ['First try', ImportField::FirstTry];
        yield 'en: solved with' => ['Solved with', ImportField::SolvedWith];
        yield 'en: partner' => ['Partner', ImportField::SolvedWith];
        yield 'en: solo/pair/team' => ['Solo/Pair/Team', ImportField::SolvingType];
        yield 'en: owned' => ['Owned', ImportField::Owned];
        yield 'en: sold' => ['Sold', ImportField::NotOwned];
        yield 'en: collection' => ['Collection', ImportField::Collection];
        yield 'en: price' => ['price', $readyMade, StandardCustomField::PricePaid];
        yield 'en: tags' => ['tags', $readyMade, StandardCustomField::MyTags];
        yield 'en: difficulty' => ['difficulty', $readyMade, StandardCustomField::HowHardItFelt];
        yield 'en: copies' => ['Copies', $readyMade, StandardCustomField::Copies];
        yield 'en: year of release' => ['Year of release', $readyMade, StandardCustomField::YearOfRelease];
        yield 'en: location' => ['Location', $readyMade, StandardCustomField::WhereItIs];

        // Czech
        yield 'cs: název' => ['Název', ImportField::PuzzleName];
        yield 'cs: titul' => ['Titul', ImportField::PuzzleName];
        yield 'cs: výrobce' => ['Výrobce', ImportField::Brand];
        yield 'cs: značka' => ['Značka', ImportField::Brand];
        yield 'cs: dílků' => ['Dílků', ImportField::Pieces];
        yield 'cs: počet dílků' => ['Počet dílků', ImportField::Pieces];
        yield 'cs: čas' => ['Čas', ImportField::Time];
        yield 'cs: datum' => ['Datum', ImportField::SolvedOn];
        yield 'cs: dokončeno' => ['Dokončeno', ImportField::SolvedOn];
        yield 'cs: čárový kód' => ['Čárový kód', ImportField::Barcode];
        yield 'cs: poznámka' => ['Poznámka', $readyMade, StandardCustomField::PrivateNotes];
        yield 'cs: stav - the status before the condition' => ['Stav', ImportField::Status];
        yield 'cs: pokus' => ['Pokus', ImportField::AttemptNumber];
        yield 'cs: první pokus' => ['První pokus', ImportField::FirstTry];
        yield 'cs: s kým' => ['S kým', ImportField::SolvedWith];
        yield 'cs: sbírka' => ['Sbírka', ImportField::Collection];
        yield 'cs: cena' => ['Cena', $readyMade, StandardCustomField::PricePaid];
        yield 'cs: dílků za minutu' => ['Dílků za minutu', ImportField::Computed];

        // German
        yield 'de: Titel' => ['Titel', ImportField::PuzzleName];
        yield 'de: Name' => ['Name', ImportField::PuzzleName];
        yield 'de: Hersteller' => ['Hersteller', ImportField::Brand];
        yield 'de: Marke' => ['Marke', ImportField::Brand];
        yield 'de: Teile' => ['Teile', ImportField::Pieces];
        yield 'de: Teilezahl' => ['Teilezahl', ImportField::Pieces];
        yield 'de: Zeit' => ['Zeit', ImportField::Time];
        yield 'de: Datum' => ['Datum', ImportField::SolvedOn];
        yield 'de: fertig' => ['fertig', ImportField::SolvedOn];
        yield 'de: Strichcode' => ['Strichcode', ImportField::Barcode];
        yield 'de: Notizen' => ['Notizen', $readyMade, StandardCustomField::PrivateNotes];
        yield 'de: Bemerkung' => ['Bemerkung', $readyMade, StandardCustomField::PrivateNotes];
        yield 'de: Status' => ['Status', ImportField::Status];
        yield 'de: Versuch' => ['Versuch', ImportField::AttemptNumber];
        yield 'de: Artikelnummer' => ['Artikelnummer', ImportField::BrandCode];
        yield 'de: Künstler' => ['Künstler', $readyMade, StandardCustomField::Artist];
        yield 'de: Größe' => ['Größe', ImportField::Pieces];

        // Spanish
        yield 'es: nombre' => ['Nombre', ImportField::PuzzleName];
        yield 'es: título' => ['Título', ImportField::PuzzleName];
        yield 'es: marca' => ['Marca', ImportField::Brand];
        yield 'es: fabricante' => ['Fabricante', ImportField::Brand];
        yield 'es: piezas' => ['Piezas', ImportField::Pieces];
        yield 'es: tiempo' => ['Tiempo', ImportField::Time];
        yield 'es: fecha' => ['Fecha', ImportField::SolvedOn];
        yield 'es: terminado' => ['Terminado', ImportField::SolvedOn];
        yield 'es: código de barras' => ['Código de barras', ImportField::Barcode];
        yield 'es: notas' => ['Notas', $readyMade, StandardCustomField::PrivateNotes];
        yield 'es: estado - the status before the condition' => ['Estado', ImportField::Status];
        yield 'es: intento' => ['Intento', ImportField::AttemptNumber];
        yield 'es: precio' => ['Precio', $readyMade, StandardCustomField::PricePaid];

        // French
        yield 'fr: titre' => ['Titre', ImportField::PuzzleName];
        yield 'fr: nom' => ['Nom', ImportField::PuzzleName];
        yield 'fr: marque' => ['Marque', ImportField::Brand];
        yield 'fr: fabricant' => ['Fabricant', ImportField::Brand];
        yield 'fr: pièces' => ['Pièces', ImportField::Pieces];
        yield 'fr: temps' => ['Temps', ImportField::Time];
        yield 'fr: date' => ['Date', ImportField::SolvedOn];
        yield 'fr: terminé' => ['Terminé', ImportField::SolvedOn];
        yield 'fr: code-barres' => ['Code-barres', ImportField::Barcode];
        yield 'fr: remarques' => ['Remarques', $readyMade, StandardCustomField::PrivateNotes];
        yield 'fr: statut' => ['Statut', ImportField::Status];
        yield 'fr: essai' => ['Essai', ImportField::AttemptNumber];
        yield 'fr: prix' => ['Prix', $readyMade, StandardCustomField::PricePaid];

        // Japanese
        yield 'ja: タイトル' => ['タイトル', ImportField::PuzzleName];
        yield 'ja: 名前' => ['名前', ImportField::PuzzleName];
        yield 'ja: メーカー' => ['メーカー', ImportField::Brand];
        yield 'ja: ブランド' => ['ブランド', ImportField::Brand];
        yield 'ja: ピース' => ['ピース', ImportField::Pieces];
        yield 'ja: ピース数' => ['ピース数', ImportField::Pieces];
        yield 'ja: タイム' => ['タイム', ImportField::Time];
        yield 'ja: 時間' => ['時間', ImportField::Time];
        yield 'ja: 日付' => ['日付', ImportField::SolvedOn];
        yield 'ja: 完成日' => ['完成日', ImportField::SolvedOn];
        yield 'ja: バーコード' => ['バーコード', ImportField::Barcode];
        yield 'ja: メモ' => ['メモ', $readyMade, StandardCustomField::PrivateNotes];
        yield 'ja: 状態 - the status before the condition' => ['状態', ImportField::Status];
        yield 'ja: 価格' => ['価格', $readyMade, StandardCustomField::PricePaid];
        yield 'ja: half-width katakana' => ['ﾀｲﾄﾙ', ImportField::PuzzleName];
    }

    #[DataProvider('headersHoldingAWord')]
    public function testAHeaderThatHoldsAFieldsWordAmongOthersIsAWeakerGuess(string $header, ImportField $field, null|StandardCustomField $readyMade = null): void
    {
        $guesses = (new HeaderMatcher())->guess($header);

        self::assertNotSame([], $guesses, $header);
        self::assertSame($field, $guesses[0]->field, $header);
        self::assertSame($readyMade, $guesses[0]->readyMadeField, $header);
        self::assertGreaterThanOrEqual(50, $guesses[0]->score);
        self::assertLessThan(FieldGuess::CONTAINED_BELOW, $guesses[0]->score);
        self::assertFalse($guesses[0]->isExact());
    }

    /**
     * @return iterable<string, array{0: string, 1: ImportField, 2?: StandardCustomField}>
     */
    public static function headersHoldingAWord(): iterable
    {
        yield 'file 3: the difference to the best attempt is worked out, not an attempt number' => ['Diff between previous best attempt', ImportField::Computed];
        yield 'file 3: the standing on MySpeedPuzzling is worked out, not "already there"' => ['Current standing on my speed puzzling', ImportField::Computed];
        yield 'file 3: the placing at a competition' => ['Placing if used at comp', ImportField::Computed];
        yield 'the other app\'s sell price is at least a price' => ['Sell price', ImportField::ReadyMadeField, StandardCustomField::PricePaid];
        yield 'the other app\'s creation date is at least a date' => ['Creation date', ImportField::SolvedOn];
        yield 'a time with more words' => ['My best time ever', ImportField::Time];
        yield 'an artist\'s name is the artist, not the puzzle\'s name' => ['Artist name', ImportField::ReadyMadeField, StandardCustomField::Artist];
        yield 'ja: a word inside a longer header' => ['パズルのタイトル一覧', ImportField::PuzzleName];
        yield 'a percentage of something' => ['Share in %', ImportField::Computed];
    }

    public function testAnExactGuessComesBeforeAHeldWord(): void
    {
        $guesses = (new HeaderMatcher())->guess('Pieces per minute');

        self::assertSame(ImportField::Computed, $guesses[0]->field);
        self::assertSame(100, $guesses[0]->score);
        self::assertSame(ImportField::Pieces, $guesses[1]->field, 'It holds "pieces" - a weaker guess, kept for the player to choose');
        self::assertLessThan(100, $guesses[1]->score);

        $missing = (new HeaderMatcher())->guess('Missing pieces');
        self::assertSame(StandardCustomField::MissingPieces, $missing[0]->readyMadeField);
        self::assertSame(ImportField::Pieces, $missing[1]->field);
    }

    public function testEqualGuessesComeInTheOrderOfTheFields(): void
    {
        // "stav" is the status and the condition; "place" is a rank and where a puzzle is kept
        $stav = (new HeaderMatcher())->guess('stav');
        self::assertSame([ImportField::Status, ImportField::ReadyMadeField], array_map(static fn (FieldGuess $guess): ImportField => $guess->field, $stav));
        self::assertSame(StandardCustomField::Condition, $stav[1]->readyMadeField);
        self::assertSame([100, 100], array_map(static fn (FieldGuess $guess): int => $guess->score, $stav));

        $place = (new HeaderMatcher())->guess('Place');
        self::assertSame(ImportField::Computed, $place[0]->field);
        self::assertSame(StandardCustomField::WhereItIs, $place[1]->readyMadeField);
    }

    public function testEveryFieldIsGuessedOnce(): void
    {
        foreach (['Date completed', 'Puzzle name', 'Price paid', 'Number of pieces'] as $header) {
            $keys = array_map(
                static fn (FieldGuess $guess): string => $guess->field->value . ':' . ($guess->readyMadeField === null ? '' : $guess->readyMadeField->value),
                (new HeaderMatcher())->guess($header),
            );

            self::assertSame(array_unique($keys), $keys, $header);
        }
    }

    public function testGuessesAreSortedByScore(): void
    {
        foreach (['Date completed', 'Diff between previous best attempt', 'Purchase price', 'Time with breaks'] as $header) {
            $scores = array_map(static fn (FieldGuess $guess): int => $guess->score, (new HeaderMatcher())->guess($header));
            $sorted = $scores;
            rsort($sorted);

            self::assertSame($sorted, $scores, $header);
        }
    }

    #[DataProvider('headersWithoutAGuess')]
    public function testNoGuess(string $header): void
    {
        self::assertSame([], (new HeaderMatcher())->guess($header), $header);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function headersWithoutAGuess(): iterable
    {
        yield 'empty' => [''];
        yield 'the task\'s blank header' => [' '];
        yield 'spaces and a tab' => ["  \t "];
        yield 'a non-breaking space' => ["\u{00A0}"];
        yield 'a direction mark' => ["\u{200F}"];
        yield 'punctuation only' => ['---'];
        yield 'the other app\'s ID' => ['ID'];
        yield 'the other app\'s picture link' => ['Picture URL'];
        yield 'the other app\'s picture id' => ['Picture id'];
        yield 'a column letter' => ['Column7'];
        yield 'a word nobody knows' => ['Zxqv'];
        yield 'a part of a word is no word' => ['Timetable'];
        yield 'a part of a word is no word, the other way' => ['Datel'];
        yield 'an emoji' => ['🧩'];
        yield 'a very long header' => [str_repeat('Title ', 200)];
    }

    public function testEveryWordOfEveryFieldIsGuessedAsThatField(): void
    {
        $matcher = new HeaderMatcher();

        foreach (ImportField::cases() as $field) {
            foreach ($field->headerWords() as $language => $words) {
                self::assertContains($language, ['en', 'cs', 'de', 'es', 'fr', 'ja']);

                foreach ($words as $word) {
                    $exact = array_filter($matcher->guess($word), static fn (FieldGuess $guess): bool => $guess->isExact() && $guess->field === $field);

                    self::assertCount(1, $exact, sprintf('"%s" (%s) is no exact guess for %s', $word, $language, $field->value));
                }
            }
        }

        foreach (StandardCustomField::cases() as $readyMade) {
            foreach ($readyMade->headerWords() as $language => $words) {
                foreach ($words as $word) {
                    $exact = array_filter($matcher->guess($word), static fn (FieldGuess $guess): bool => $guess->isExact() && $guess->readyMadeField === $readyMade);

                    self::assertCount(1, $exact, sprintf('"%s" (%s) is no exact guess for %s', $word, $language, $readyMade->value));
                }
            }
        }
    }

    public function testEveryFieldOfAPuzzleAndAResultHasWordsInSixLanguages(): void
    {
        foreach (ImportField::cases() as $field) {
            if ($field === ImportField::ReadyMadeField) {
                self::assertSame([], $field->headerWords(), 'The ready-made fields carry their own words');

                continue;
            }

            self::assertSame(['en', 'cs', 'de', 'es', 'fr', 'ja'], array_keys($field->headerWords()), $field->value);

            foreach ($field->headerWords() as $words) {
                self::assertNotSame([], $words, $field->value);
            }
        }
    }

    public function testAHeaderAsItIsCompared(): void
    {
        self::assertSame('no of pieces', HeaderMatcher::words('No. of Pieces'));
        self::assertSame('entered on msp', HeaderMatcher::words('Entered on MSP?'));
        self::assertSame('number pieces', HeaderMatcher::words('# pieces'));
        self::assertSame('in top percent', HeaderMatcher::words('In top %'));
        self::assertSame('nazev', HeaderMatcher::words('  Název  '));
        self::assertSame('date dachat', HeaderMatcher::words('Date d\'achat'));
        self::assertSame('ピース数', HeaderMatcher::words('ピース数'));
        self::assertSame('', HeaderMatcher::words(' ?? '));
    }
}
